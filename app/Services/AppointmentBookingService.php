<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStatusHistory;
use App\Models\ServiceTariff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function book(int $slotId, int $clientId, ?int $caseId, int $actorId, ?string $notes = null): Appointment
    {
        $store = Cache::store(config('appointments.lock_store', 'redis'));
        return $store->lock('ensha:appointment-slot:'.$slotId, 15)->block(5, function () use ($slotId, $clientId, $caseId, $actorId, $notes) {
            return DB::transaction(function () use ($slotId, $clientId, $caseId, $actorId, $notes) {
                $slot = AppointmentSlot::query()->lockForUpdate()->findOrFail($slotId);
                DB::table('users')->where('id', $slot->counselor_id)->lockForUpdate()->first();
                if ($slot->status !== 'available' || $slot->booked_count >= $slot->capacity || $slot->starts_at->isPast()) {
                    throw ValidationException::withMessages(['slot_id' => 'این زمان دیگر قابل رزرو نیست.']);
                }
                $duplicate = Appointment::where('client_id', $clientId)->whereNotIn('status', ['cancelled'])
                    ->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at)->exists();
                if ($duplicate) throw ValidationException::withMessages(['client_id' => 'مراجع در این بازه نوبت دیگری دارد.']);
                $counselorBusy = Appointment::where('counselor_id', $slot->counselor_id)->whereNotIn('status', ['cancelled'])
                    ->where('slot_id', '!=', $slot->id)
                    ->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at)->exists();
                if ($counselorBusy) throw ValidationException::withMessages(['slot_id' => 'مشاور در این بازه نوبت فعال دیگری دارد.']);

                $tariff = $this->tariff($slot);
                $seat = ((int) Appointment::withTrashed()->where('slot_id', $slot->id)->max('seat_number')) + 1;
                $appointment = Appointment::create([
                    'appointment_number' => 'APT-'.$slot->starts_at->format('ymd').'-'.str_pad((string) $slot->id, 6, '0', STR_PAD_LEFT).'-'.$seat,
                    'centre_id' => $slot->centre_id, 'branch_id' => $slot->branch_id,
                    'client_id' => $clientId, 'case_id' => $caseId, 'topic_id' => $slot->topic_id,
                    'counselor_id' => $slot->counselor_id, 'room_id' => $slot->room_id,
                    'slot_id' => $slot->id, 'tariff_id' => $tariff?->id, 'seat_number' => $seat,
                    'mode' => $slot->mode, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at,
                    'status' => 'pending', 'price' => $tariff?->price ?? $slot->topic->price,
                    'counselor_pay' => $tariff?->counselor_pay ?? 0, 'currency' => $tariff?->currency ?? 'IRR',
                    'notes' => $notes, 'created_by' => $actorId,
                ]);
                $slot->increment('booked_count');
                $slot->increment('lock_version');
                $slot->refresh();
                if ($slot->booked_count >= $slot->capacity) $slot->update(['status' => 'full']);
                AppointmentStatusHistory::create(['appointment_id' => $appointment->id, 'from_status' => null, 'to_status' => 'pending', 'changed_by' => $actorId, 'reason' => 'ایجاد نوبت', 'changed_at' => now()]);
                return $appointment;
            }, 3);
        });
    }

    public function transition(Appointment $appointment, string $status, int $actorId, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $status, $actorId, $reason) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if (! $appointment->canTransitionTo($status)) throw ValidationException::withMessages(['status' => 'این انتقال وضعیت مجاز نیست.']);
            $from = $appointment->status;
            $appointment->update(['status' => $status, 'cancellation_reason' => $status === 'cancelled' ? $reason : $appointment->cancellation_reason]);
            AppointmentStatusHistory::create(['appointment_id' => $appointment->id, 'from_status' => $from, 'to_status' => $status, 'changed_by' => $actorId, 'reason' => $reason, 'changed_at' => now()]);
            if ($status === 'cancelled') {
                $slot = AppointmentSlot::query()->lockForUpdate()->find($appointment->slot_id);
                if ($slot) $slot->update(['booked_count' => max(0, $slot->booked_count - 1), 'status' => 'available', 'lock_version' => $slot->lock_version + 1]);
            }
            return $appointment;
        }, 3);
    }

    private function tariff(AppointmentSlot $slot): ?ServiceTariff
    {
        return ServiceTariff::effectiveOn($slot->slot_date->toDateString())->where('centre_id', $slot->centre_id)->where('topic_id', $slot->topic_id)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $slot->branch_id))
            ->where(fn ($q) => $q->whereNull('counselor_id')->orWhere('counselor_id', $slot->counselor_id))
            ->orderByRaw('counselor_id IS NULL')->orderByRaw('branch_id IS NULL')->orderByDesc('version')->first();
    }
}
