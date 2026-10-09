<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStatusHistory;
use App\Models\CounsellingCase;
use App\Models\ServiceTariff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function book(int $slotId, int $clientId, ?int $caseId, int $actorId, ?string $notes = null, array $options = []): Appointment
    {
        $store = Cache::store(config('appointments.lock_store', 'redis'));
        return $store->lock('ensha:appointment-slot:'.$slotId, 15)->block(5, function () use ($slotId, $clientId, $caseId, $actorId, $notes, $options) {
            return DB::transaction(function () use ($slotId, $clientId, $caseId, $actorId, $notes, $options) {
                $slot = AppointmentSlot::query()->lockForUpdate()->findOrFail($slotId);
                DB::table('users')->where('id', $slot->counselor_id)->lockForUpdate()->first();
                DB::table('clients')->where('id',$clientId)->lockForUpdate()->first();
                if ($slot->room_id) DB::table('centre_rooms')->where('id',$slot->room_id)->lockForUpdate()->first();
                if ($slot->status !== 'available' || $slot->booked_count >= $slot->capacity || ($slot->starts_at->isPast() && (! BookingPolicy::forCentre((int) $slot->centre_id)->allow_past_bookings || ($options['source'] ?? '') !== 'secretary'))) {
                    throw ValidationException::withMessages(['slot_id' => 'این زمان دیگر قابل رزرو نیست.']);
                }
                app(AppointmentAvailabilityService::class)->assertBookable($slot, $clientId, null, ($options['source'] ?? '') === 'secretary');
                $duplicate = Appointment::where('client_id', $clientId)->whereNotIn('status', ['cancelled'])
                    ->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at)->exists();
                if ($duplicate) throw ValidationException::withMessages(['client_id' => 'مراجع در این بازه نوبت دیگری دارد.']);
                $counselorBusy = Appointment::where('counselor_id', $slot->counselor_id)->whereNotIn('status', ['cancelled'])
                    ->where('slot_id', '!=', $slot->id)
                    ->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at)->exists();
                if ($counselorBusy) throw ValidationException::withMessages(['slot_id' => 'مشاور در این بازه نوبت فعال دیگری دارد.']);

                $tariff = $this->tariff($slot);
                // Stage 09: money is posted only through the immutable payment ledger.
                $quote = app(AppointmentPricingService::class)->quote($slot, $options['discount_id'] ?? null, 0, $tariff?->price);
                if (! $tariff && ! $slot->topic->price && ! $quote['unit_price_snapshot']) throw ValidationException::withMessages(['slot_id'=>'تعرفه معتبر نیست.']);
                if (! $caseId) {
                    $setting = DB::table('client_record_settings')->where('centre_id',$slot->centre_id)->first();
                    if (($setting?->draft_on ?? 'booking') === 'booking') {
                        $caseId = CounsellingCase::where('client_id',$clientId)->where('centre_id',$slot->centre_id)->where('status','draft')->value('id');
                        $caseId ??= CounsellingCase::create(['client_id'=>$clientId,'centre_id'=>$slot->centre_id,
                            'case_number'=>'CASE-'.strtoupper(bin2hex(random_bytes(6))), 'title'=>'پرونده اولیه نوبت',
                            'status'=>'draft','created_by'=>$actorId])->id;
                    }
                }
                do { $publicId = (string) random_int(1000000000000, 9999999999999); }
                while (Appointment::withTrashed()->where('public_id',$publicId)->exists());
                $status = DB::table('appointment_statuses')->where('centre_id',$slot->centre_id)->where('is_initial',true)->where('is_active',true)->first();
                $seat = ((int) Appointment::withTrashed()->where('slot_id', $slot->id)->max('seat_number')) + 1;
                $appointment = Appointment::create([
                    'appointment_number' => 'APT-'.$slot->starts_at->format('ymd').'-'.str_pad((string) $slot->id, 6, '0', STR_PAD_LEFT).'-'.$seat,
                    'centre_id' => $slot->centre_id, 'branch_id' => $slot->branch_id,
                    'client_id' => $clientId, 'case_id' => $caseId, 'topic_id' => $slot->topic_id,
                    'counselor_id' => $slot->counselor_id, 'room_id' => $slot->room_id,
                    'slot_id' => $slot->id, 'tariff_id' => $tariff?->id, 'seat_number' => $seat,
                    'mode' => $slot->mode, 'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at,
                    'status' => 'pending', 'price' => $quote['final_price'],
                    'public_id'=>$publicId,'source'=>$options['source'] ?? 'secretary',
                    'topic_name_snapshot'=>$slot->topic->name,'topic_color_snapshot'=>$slot->topic->color,
                    'status_id'=>$status?->id,'status_snapshot'=>$status?->name ?? 'نوبت',
                    'payment_note'=>null,'updated_by'=>$actorId,
                    ...$quote,
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
            $statusRecord = DB::table('appointment_statuses')->where('centre_id',$appointment->centre_id)->where('slug',$status)->where('is_active',true)->first();
            $appointment->update(['status' => $status, 'status_id'=>$statusRecord?->id,'status_snapshot'=>$statusRecord?->name ?? $status,
                'updated_by'=>$actorId,'cancellation_reason' => $status === 'cancelled' ? $reason : $appointment->cancellation_reason]);
            AppointmentStatusHistory::create(['appointment_id' => $appointment->id, 'from_status' => $from, 'to_status' => $status, 'changed_by' => $actorId, 'reason' => $reason, 'changed_at' => now()]);
            if ($status === 'cancelled' || ($statusRecord && ! $statusRecord->blocks_slot && $statusRecord->is_final)) {
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
