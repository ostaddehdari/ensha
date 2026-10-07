<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentAvailabilityService
{
    public function assertBookable(AppointmentSlot $slot, ?int $clientId, ?int $exceptAppointmentId = null, bool $allowPastManual = false): void
    {
        $date = $slot->starts_at->toDateString(); $time = $slot->starts_at->format('H:i:s'); $end = $slot->ends_at->format('H:i:s');
        $fail = fn (string $message) => throw ValidationException::withMessages(['slot_id'=>$message]);
        $policy = BookingPolicy::forCentre((int) $slot->centre_id);
        if (($slot->starts_at->isPast() && (! $policy->allow_past_bookings || ! $allowPastManual)) || $slot->status === 'blocked') $fail('زمان قابل رزرو نیست.');
        $topic = $slot->topic;
        if (! $topic || ! $topic->is_active || ! in_array($slot->mode, $topic->allowed_modes ?: ['in_person'], true)) $fail('موضوع یا نوع ارائه غیرفعال است.');
        $mapping = DB::table('counselor_topics')->where('user_id',$slot->counselor_id)->where('topic_id',$slot->topic_id)
            ->where('is_active',true)->where(fn ($q) => $q->whereNull('centre_id')->orWhere('centre_id',$slot->centre_id))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from','<=',$date))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until','>=',$date))->exists();
        if (! $mapping) $fail('موضوع به مشاور در این تاریخ تخصیص ندارد.');
        if (! DB::table('user_role_centres')->where('user_id',$slot->counselor_id)->where('centre_id',$slot->centre_id)->exists()) $fail('مشاور عضو این مرکز نیست.');
        if ($slot->branch_id && ! DB::table('centre_branches')->where('id',$slot->branch_id)->where('centre_id',$slot->centre_id)->exists()) $fail('شعبه نامعتبر است.');
        $shift = DB::table('counselor_shifts')->where('user_id',$slot->counselor_id)->where('centre_id',$slot->centre_id)
            ->where('weekday',$slot->starts_at->dayOfWeek)->where('is_active',true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id',$slot->branch_id))
            ->where('starts_at','<=',$time)->where('ends_at','>=',$end)->exists();
        $override = DB::table('schedule_exceptions')->where('centre_id',$slot->centre_id)->where('user_id',$slot->counselor_id)
            ->whereDate('exception_date',$date)->where('type','override')->where('starts_at','<=',$time)->where('ends_at','>=',$end)->exists();
        if (! $shift && ! $override) $fail('مشاور در این بازه ساعت کاری ندارد.');
        if (DB::table('official_holidays')->where('is_active',true)->whereDate('gregorian_date',$date)->exists()
            || DB::table('centre_closures')->where('centre_id',$slot->centre_id)->whereDate('starts_on','<=',$date)->whereDate('ends_on','>=',$date)->exists()) $fail('مرکز در این روز تعطیل است.');
        if (DB::table('counselor_leaves')->where('centre_id',$slot->centre_id)->where('user_id',$slot->counselor_id)
            ->where('starts_at','<',$slot->ends_at)->where('ends_at','>',$slot->starts_at)->exists()
            || DB::table('schedule_exceptions')->where('centre_id',$slot->centre_id)->where('user_id',$slot->counselor_id)
                ->whereDate('exception_date',$date)->where('type','unavailable')
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere(fn ($r) => $r->where('starts_at','<',$end)->where('ends_at','>',$time)))->exists()) $fail('مشاور در این زمان مرخصی یا عدم حضور دارد.');
        $active = fn ($q) => $q->whereNotIn('status',['cancelled','no_show']);
        $overlap = fn ($q) => $q->where('starts_at','<',$slot->ends_at)->where('ends_at','>',$slot->starts_at)
            ->when($exceptAppointmentId, fn ($q) => $q->where('id','!=',$exceptAppointmentId));
        if ($clientId && $overlap($active(Appointment::where('client_id',$clientId)))->exists()) $fail('مراجع در این زمان نوبت دیگری دارد.');
        if ($overlap($active(Appointment::where('counselor_id',$slot->counselor_id)->where('slot_id','!=',$slot->id)))->exists()) $fail('مشاور در این زمان نوبت دیگری دارد.');
        if ($policy->check_rooms && $slot->mode === 'in_person' && $topic->requires_room) {
            $room = DB::table('centre_rooms')->where('id',$slot->room_id)->where('centre_id',$slot->centre_id)->where('is_active',true)->first();
            if (! $room || ($room->branch_id && $room->branch_id != $slot->branch_id)) $fail('اتاق مناسب فعال نیست.');
            if ($overlap($active(Appointment::where('room_id',$slot->room_id)))->count() >= $room->capacity) $fail('ظرفیت اتاق تکمیل شده است.');
        }
        if ($clientId && ! DB::table('clients')->where('id',$clientId)->where('centre_id',$slot->centre_id)->where('status','active')->whereNull('merged_into_id')->exists()) $fail('مراجع به این مرکز تعلق ندارد.');
    }
}
