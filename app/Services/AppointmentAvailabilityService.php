<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\ServiceTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentAvailabilityService
{
    private const CONFLICT_LOOKAROUND_MINUTES = 1440;

    public function assertBookable(
        AppointmentSlot $slot,
        ?int $clientId,
        ?int $exceptAppointmentId = null,
        bool $allowPastManual = false,
    ): void {
        $topic = $this->topic($slot);
        $date = $slot->starts_at->toDateString();
        $time = $slot->starts_at->format('H:i:s');
        $end = $slot->ends_at->format('H:i:s');
        $fail = fn (string $message) => throw ValidationException::withMessages(['slot_id' => $message]);
        $policy = BookingPolicy::forCentre((int) $slot->centre_id);

        $duration = $slot->starts_at->diffInMinutes($slot->ends_at, false);
        if ($duration < 15 || $duration > 240 || $slot->ends_at->toDateString() !== $date) {
            $fail('بازه نوبت باید معتبر و در همان روز باشد.');
        }
        if (($slot->starts_at->isPast() && (! $policy->allow_past_bookings || ! $allowPastManual)) || $slot->status === 'blocked') {
            $fail('زمان قابل رزرو نیست.');
        }
        if (! $topic || ! $topic->is_active || ! in_array($slot->mode, $topic->allowed_modes ?: ['in_person'], true)) {
            $fail('موضوع یا نوع ارائه غیرفعال است.');
        }
        if ((int) $slot->capacity < 1 || (int) $slot->capacity > max(1, (int) $topic->capacity)) {
            $fail('ظرفیت اسلات با ظرفیت خدمت سازگار نیست.');
        }

        $mapping = DB::table('counselor_topics')->where('user_id', $slot->counselor_id)->where('topic_id', $slot->topic_id)
            ->where('is_active', true)->where(fn ($q) => $q->whereNull('centre_id')->orWhere('centre_id', $slot->centre_id))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))->exists();
        if (! $mapping) {
            $fail('موضوع به مشاور در این تاریخ تخصیص ندارد.');
        }
        if (! DB::table('user_role_centres')->where('user_id', $slot->counselor_id)->where('centre_id', $slot->centre_id)->exists()) {
            $fail('مشاور عضو این مرکز نیست.');
        }
        if ($slot->branch_id && ! DB::table('centre_branches')->where('id', $slot->branch_id)->where('centre_id', $slot->centre_id)->exists()) {
            $fail('شعبه نامعتبر است.');
        }

        $branchScope = fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $slot->branch_id);
        $shift = DB::table('counselor_shifts')->where('user_id', $slot->counselor_id)->where('centre_id', $slot->centre_id)
            ->where('weekday', $slot->starts_at->dayOfWeek)->where('is_active', true)
            ->where($branchScope)->where('starts_at', '<=', $time)->where('ends_at', '>=', $end)->exists();
        $override = DB::table('schedule_exceptions')->where('centre_id', $slot->centre_id)->where('user_id', $slot->counselor_id)
            ->where($branchScope)->whereDate('exception_date', $date)->where('type', 'override')
            ->where('starts_at', '<=', $time)->where('ends_at', '>=', $end)->exists();
        if (! $shift && ! $override) {
            $fail('مشاور در این بازه ساعت کاری ندارد.');
        }

        if (DB::table('official_holidays')->where('is_active', true)->whereDate('gregorian_date', $date)->exists()
            || DB::table('centre_closures')->where('centre_id', $slot->centre_id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) {
            $fail('مرکز در این روز تعطیل است.');
        }
        if (DB::table('counselor_leaves')->where('centre_id', $slot->centre_id)->where('user_id', $slot->counselor_id)
            ->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at)->exists()
            || DB::table('schedule_exceptions')->where('centre_id', $slot->centre_id)->where('user_id', $slot->counselor_id)
                ->where($branchScope)->whereDate('exception_date', $date)->where('type', 'unavailable')
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere(fn ($r) => $r->where('starts_at', '<', $end)->where('ends_at', '>', $time)))->exists()) {
            $fail('مشاور در این زمان مرخصی یا عدم حضور دارد.');
        }

        $candidateBreak = min(self::CONFLICT_LOOKAROUND_MINUTES, max(0, (int) $topic->break_minutes));
        if ($clientId && $this->conflicts(
            Appointment::where('client_id', $clientId), $slot, $candidateBreak, $exceptAppointmentId,
        )->isNotEmpty()) {
            $fail('مراجع در این زمان نوبت دیگری دارد.');
        }

        $counselorQuery = Appointment::where('counselor_id', $slot->counselor_id);
        if ($slot->exists) {
            $counselorQuery->where('slot_id', '!=', $slot->id);
        }
        if ($this->conflicts($counselorQuery, $slot, $candidateBreak, $exceptAppointmentId)->isNotEmpty()) {
            $fail('مشاور در این زمان یا فاصله استراحت نوبت دیگری دارد.');
        }

        if ($policy->check_rooms && $slot->mode === 'in_person' && $topic->requires_room) {
            $this->assertRoomCanHost($slot, $candidateBreak, $exceptAppointmentId, $fail);
        }
        if ($clientId && ! DB::table('clients')->where('id', $clientId)->where('centre_id', $slot->centre_id)
            ->where('status', 'active')->whereNull('merged_into_id')->exists()) {
            $fail('مراجع به این مرکز تعلق ندارد.');
        }
    }

    public function assignAvailableRoom(AppointmentSlot $slot, ?int $exceptAppointmentId = null): int
    {
        $topic = $this->topic($slot);
        $query = DB::table('centre_rooms as rooms')
            ->where('rooms.centre_id', $slot->centre_id)->where('rooms.is_active', true)
            ->where('rooms.capacity', '>=', max(1, (int) $slot->capacity))
            ->where(function ($q) use ($slot) {
                if ($slot->branch_id) {
                    $q->whereNull('rooms.branch_id')->orWhere('rooms.branch_id', $slot->branch_id);
                } else {
                    $q->whereNull('rooms.branch_id');
                }
            })
            ->where(function ($q) use ($slot) {
                $q->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('room_topics')
                    ->whereColumn('room_topics.room_id', 'rooms.id'))
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('room_topics')
                        ->whereColumn('room_topics.room_id', 'rooms.id')->where('room_topics.topic_id', $slot->topic_id));
            })
            ->orderBy('rooms.id');

        $candidateBreak = min(self::CONFLICT_LOOKAROUND_MINUTES, max(0, (int) ($topic?->break_minutes ?? 0)));
        foreach ($query->get() as $room) {
            $candidate = clone $slot;
            $candidate->room_id = $room->id;
            if ($this->roomConflictCount($candidate, $candidateBreak, $exceptAppointmentId) < (int) $room->capacity) {
                return (int) $room->id;
            }
        }

        throw ValidationException::withMessages(['slot_id' => 'اتاق سازگار و دارای ظرفیت برای این زمان وجود ندارد.']);
    }

    private function assertRoomCanHost(
        AppointmentSlot $slot,
        int $candidateBreak,
        ?int $exceptAppointmentId,
        callable $fail,
    ): void {
        $room = DB::table('centre_rooms')->where('id', $slot->room_id)->where('centre_id', $slot->centre_id)
            ->where('is_active', true)->first();
        if (! $room || ($room->branch_id && (int) $room->branch_id !== (int) $slot->branch_id)
            || (int) $room->capacity < max(1, (int) $slot->capacity)) {
            $fail('اتاق مناسب و دارای ظرفیت فعال نیست.');
        }
        $hasMappings = DB::table('room_topics')->where('room_id', $slot->room_id)->exists();
        if ($hasMappings && ! DB::table('room_topics')->where('room_id', $slot->room_id)->where('topic_id', $slot->topic_id)->exists()) {
            $fail('اتاق برای این خدمت قابل استفاده نیست.');
        }
        if ($this->roomConflictCount($slot, $candidateBreak, $exceptAppointmentId) >= (int) $room->capacity) {
            $fail('ظرفیت اتاق یا فاصله استراحت آن تکمیل شده است.');
        }
    }

    private function roomConflictCount(AppointmentSlot $slot, int $candidateBreak, ?int $exceptAppointmentId): int
    {
        if (! $slot->room_id) {
            return PHP_INT_MAX;
        }

        return $this->conflicts(
            Appointment::where('room_id', $slot->room_id), $slot, $candidateBreak, $exceptAppointmentId,
        )->count();
    }

    private function conflicts(
        Builder $query,
        AppointmentSlot $slot,
        int $candidateBreak,
        ?int $exceptAppointmentId,
    ): Collection {
        return $query->whereNotIn('appointments.status', ['cancelled', 'no_show'])
            ->when($exceptAppointmentId, fn ($q) => $q->where('appointments.id', '!=', $exceptAppointmentId))
            ->where('appointments.starts_at', '<', $slot->ends_at->copy()->addMinutes(self::CONFLICT_LOOKAROUND_MINUTES))
            ->where('appointments.ends_at', '>', $slot->starts_at->copy()->subMinutes(self::CONFLICT_LOOKAROUND_MINUTES))
            ->leftJoin('service_topics as conflict_topics', 'conflict_topics.id', '=', 'appointments.topic_id')
            ->select('appointments.*', 'conflict_topics.break_minutes as conflict_break_minutes')
            ->get()->filter(function (Appointment $appointment) use ($slot, $candidateBreak) {
                $existingBreak = min(self::CONFLICT_LOOKAROUND_MINUTES, max(0, (int) $appointment->conflict_break_minutes));

                return $slot->starts_at->lessThan($appointment->ends_at->copy()->addMinutes($existingBreak))
                    && $slot->ends_at->copy()->addMinutes($candidateBreak)->greaterThan($appointment->starts_at);
            });
    }

    private function topic(AppointmentSlot $slot): ?ServiceTopic
    {
        if ($slot->relationLoaded('topic')) {
            return $slot->topic;
        }

        $topic = ServiceTopic::find($slot->topic_id);
        $slot->setRelation('topic', $topic);

        return $topic;
    }
}
