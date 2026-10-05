<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentRescheduleService
{
    public function move(Appointment $appointment, AppointmentSlot $target, int $actorId): Appointment
    {
        return DB::transaction(function () use ($appointment,$target,$actorId) {
            $current=Appointment::lockForUpdate()->findOrFail($appointment->id);
            $target=AppointmentSlot::lockForUpdate()->findOrFail($target->id);
            if ($target->id===$current->slot_id) return $current;
            if ($target->centre_id !== $current->centre_id || $target->topic_id !== $current->topic_id || $target->status !== 'available'
                || $target->booked_count >= $target->capacity || in_array($current->status,['cancelled','no_show','completed'],true))
                throw ValidationException::withMessages(['slot_id'=>'مقصد برای این نوبت مجاز نیست.']);
            DB::table('users')->where('id',$target->counselor_id)->lockForUpdate()->first();
            DB::table('clients')->where('id',$current->client_id)->lockForUpdate()->first();
            if ($target->room_id) DB::table('centre_rooms')->where('id',$target->room_id)->lockForUpdate()->first();
            app(AppointmentAvailabilityService::class)->assertBookable($target,$current->client_id,$current->id);
            $old=AppointmentSlot::lockForUpdate()->findOrFail($current->slot_id);
            $seat=((int) Appointment::withTrashed()->where('slot_id',$target->id)->max('seat_number'))+1;
            $old->update(['booked_count'=>max(0,$old->booked_count-1),'status'=>'available','lock_version'=>$old->lock_version+1]);
            $target->update(['booked_count'=>$target->booked_count+1,
                'status'=>$target->booked_count+1 >= $target->capacity?'full':'available','lock_version'=>$target->lock_version+1]);
            $current->update(['slot_id'=>$target->id,'counselor_id'=>$target->counselor_id,'branch_id'=>$target->branch_id,
                'room_id'=>$target->room_id,'mode'=>$target->mode,'starts_at'=>$target->starts_at,'ends_at'=>$target->ends_at,
                'seat_number'=>$seat,'duration_minutes'=>$target->starts_at->diffInMinutes($target->ends_at),'updated_by'=>$actorId]);
            AppointmentStatusHistory::create(['appointment_id'=>$current->id,'from_status'=>$current->status,'to_status'=>$current->status,
                'changed_by'=>$actorId,'reason'=>'جابجایی زمان یا مشاور','metadata'=>['old_slot_id'=>$old->id,'new_slot_id'=>$target->id],
                'changed_at'=>now()]);
            return $current->fresh();
        },3);
    }
}
