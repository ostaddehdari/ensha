<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentRescheduleService
{
    public function move(Appointment $appointment, AppointmentSlot $target, int $actorId, bool $allowPastManual = false): Appointment
    {
        $source = AppointmentSlot::findOrFail($appointment->slot_id);
        $keys = app(AppointmentResourceLockService::class)->keys(
            (int) $appointment->centre_id,
            [(int) $source->counselor_id, (int) $target->counselor_id],
            [(int) $source->id, (int) $target->id],
            (bool) ($source->room_id || $target->room_id),
        );

        return app(AppointmentResourceLockService::class)->block($keys, function () use ($appointment, $target, $actorId, $allowPastManual) {
            return DB::transaction(function () use ($appointment, $target, $actorId, $allowPastManual) {
                $current = Appointment::lockForUpdate()->findOrFail($appointment->id);
                $target = AppointmentSlot::lockForUpdate()->findOrFail($target->id);

                return $this->moveLocked($current, $target, $actorId, $allowPastManual);
            }, 3);
        });
    }

    public function moveToRange(
        Appointment $appointment,
        int $counselorId,
        Carbon $start,
        Carbon $end,
        int $actorId,
        bool $allowPastManual = false,
    ): Appointment {
        $appointment->loadMissing(['slot', 'topic']);
        $source = $appointment->slot;
        $needsRoom = BookingPolicy::forCentre((int) $appointment->centre_id)->check_rooms
            && $appointment->mode === 'in_person' && (bool) $appointment->topic?->requires_room;
        $keys = app(AppointmentResourceLockService::class)->keys(
            (int) $appointment->centre_id,
            [(int) $source->counselor_id, $counselorId],
            [(int) $source->id],
            (bool) ($source->room_id || $needsRoom),
        );

        return app(AppointmentResourceLockService::class)->block($keys, function () use (
            $appointment, $counselorId, $start, $end, $actorId, $allowPastManual, $needsRoom,
        ) {
            return DB::transaction(function () use (
                $appointment, $counselorId, $start, $end, $actorId, $allowPastManual, $needsRoom,
            ) {
                $current = Appointment::with('topic')->lockForUpdate()->findOrFail($appointment->id);
                $source = AppointmentSlot::lockForUpdate()->findOrFail($current->slot_id);
                DB::table('users')->whereIn('id', array_values(array_unique([(int) $source->counselor_id, $counselorId])))
                    ->orderBy('id')->lockForUpdate()->get();
                DB::table('clients')->where('id', $current->client_id)->lockForUpdate()->first();
                if ($needsRoom || $source->room_id) {
                    DB::table('centre_rooms')->where('centre_id', $current->centre_id)->orderBy('id')->lockForUpdate()->get();
                }

                $sameSlot = (int) $source->counselor_id === $counselorId
                    && (int) $source->topic_id === (int) $current->topic_id
                    && $source->mode === $current->mode
                    && $source->starts_at->equalTo($start);

                if ($sameSlot) {
                    return $this->resizeLocked($current, $source, $start, $end, $actorId, $allowPastManual);
                }

                $target = AppointmentSlot::where('centre_id', $current->centre_id)
                    ->where('counselor_id', $counselorId)->where('topic_id', $current->topic_id)
                    ->where('starts_at', $start)->where('mode', $current->mode)
                    ->where('id', '!=', $source->id)->lockForUpdate()->first();
                if ($target && (! $target->ends_at->equalTo($end) || $target->status !== 'available')) {
                    throw ValidationException::withMessages(['start' => 'زمان مقصد قبلاً اشغال شده است.']);
                }

                if (! $target) {
                    $target = new AppointmentSlot([
                        'centre_id' => $current->centre_id,
                        'branch_id' => $current->branch_id,
                        'topic_id' => $current->topic_id,
                        'counselor_id' => $counselorId,
                        'slot_date' => $start->toDateString(),
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'mode' => $current->mode,
                        'capacity' => max(1, (int) $current->topic?->capacity),
                        'booked_count' => 0,
                        'status' => 'available',
                        'source' => 'secretary_manual',
                    ]);
                    $target->setRelation('topic', $current->topic);
                    if ($needsRoom) {
                        $target->room_id = app(AppointmentAvailabilityService::class)
                            ->assignAvailableRoom($target, $current->id);
                    }
                    app(AppointmentAvailabilityService::class)
                        ->assertBookable($target, $current->client_id, $current->id, $allowPastManual);
                    $target->save();
                }

                return $this->moveLocked($current, $target, $actorId, $allowPastManual);
            }, 3);
        });
    }

    private function resizeLocked(
        Appointment $appointment,
        AppointmentSlot $slot,
        Carbon $start,
        Carbon $end,
        int $actorId,
        bool $allowPastManual,
    ): Appointment {
        if ((int) $slot->booked_count > 1) {
            throw ValidationException::withMessages(['end' => 'اسلات گروهی دارای چند نوبت را نمی‌توان از تقویم تغییر مدت داد.']);
        }

        $candidate = clone $slot;
        $candidate->starts_at = $start;
        $candidate->ends_at = $end;
        $candidate->slot_date = $start->toDateString();
        $candidate->setRelation('topic', $appointment->topic);
        app(AppointmentAvailabilityService::class)
            ->assertBookable($candidate, $appointment->client_id, $appointment->id, $allowPastManual);

        $oldEnd = $slot->ends_at->toIso8601String();
        $slot->update([
            'slot_date' => $start->toDateString(),
            'starts_at' => $start,
            'ends_at' => $end,
            'lock_version' => $slot->lock_version + 1,
        ]);
        $appointment->update([
            'starts_at' => $start,
            'ends_at' => $end,
            'duration_minutes' => $start->diffInMinutes($end),
            'updated_by' => $actorId,
        ]);
        AppointmentStatusHistory::create([
            'appointment_id' => $appointment->id,
            'from_status' => $appointment->status,
            'to_status' => $appointment->status,
            'changed_by' => $actorId,
            'reason' => 'تغییر مدت نوبت',
            'metadata' => ['slot_id' => $slot->id, 'old_end' => $oldEnd, 'new_end' => $end->toIso8601String()],
            'changed_at' => now(),
        ]);

        return $appointment->fresh();
    }

    private function moveLocked(Appointment $current, AppointmentSlot $target, int $actorId, bool $allowPastManual): Appointment
    {
        if ($target->id === $current->slot_id) {
            return $current;
        }
        if ($target->centre_id !== $current->centre_id || $target->topic_id !== $current->topic_id || $target->status !== 'available'
            || $target->booked_count >= $target->capacity || in_array($current->status, ['cancelled', 'no_show', 'completed'], true)) {
            throw ValidationException::withMessages(['slot_id' => 'مقصد برای این نوبت مجاز نیست.']);
        }

        DB::table('users')->where('id', $target->counselor_id)->lockForUpdate()->first();
        DB::table('clients')->where('id', $current->client_id)->lockForUpdate()->first();
        if ($target->room_id) {
            DB::table('centre_rooms')->where('id', $target->room_id)->lockForUpdate()->first();
        }
        app(AppointmentAvailabilityService::class)
            ->assertBookable($target, $current->client_id, $current->id, $allowPastManual);

        $old = AppointmentSlot::lockForUpdate()->findOrFail($current->slot_id);
        $seat = ((int) Appointment::withTrashed()->where('slot_id', $target->id)->max('seat_number')) + 1;
        $oldCount = max(0, (int) $old->booked_count - 1);
        $old->update([
            'booked_count' => $oldCount,
            'status' => $oldCount >= (int) $old->capacity ? 'full' : 'available',
            'lock_version' => $old->lock_version + 1,
        ]);
        $targetCount = (int) $target->booked_count + 1;
        $target->update([
            'booked_count' => $targetCount,
            'status' => $targetCount >= (int) $target->capacity ? 'full' : 'available',
            'lock_version' => $target->lock_version + 1,
        ]);
        $current->update([
            'slot_id' => $target->id,
            'counselor_id' => $target->counselor_id,
            'branch_id' => $target->branch_id,
            'room_id' => $target->room_id,
            'mode' => $target->mode,
            'starts_at' => $target->starts_at,
            'ends_at' => $target->ends_at,
            'seat_number' => $seat,
            'duration_minutes' => $target->starts_at->diffInMinutes($target->ends_at),
            'updated_by' => $actorId,
        ]);
        AppointmentStatusHistory::create([
            'appointment_id' => $current->id,
            'from_status' => $current->status,
            'to_status' => $current->status,
            'changed_by' => $actorId,
            'reason' => 'جابجایی زمان یا مشاور',
            'metadata' => ['old_slot_id' => $old->id, 'new_slot_id' => $target->id],
            'changed_at' => now(),
        ]);

        return $current->fresh();
    }
}
