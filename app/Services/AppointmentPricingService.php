<?php

namespace App\Services;

use App\Models\AppointmentSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentPricingService
{
    public function quote(AppointmentSlot $slot, ?int $discountId = null, int $paid = 0, ?int $tariffPrice = null): array
    {
        $topic = $slot->topic;
        $mapping = DB::table('counselor_topics')->where('user_id',$slot->counselor_id)->where('topic_id',$slot->topic_id)->first();
        $minutes = max(1, (int) $slot->starts_at->diffInMinutes($slot->ends_at));
        $unitMinutes = max(1, (int) ($mapping?->duration_override ?: $topic->session_minutes));
        $unitPrice = (int) ($mapping?->price_override ?? $tariffPrice ?? $topic->price);
        $base = (int) round($unitPrice * $minutes / $unitMinutes);
        $discount = null;
        if ($discountId) {
            $discount = DB::table('discounts')->where('id',$discountId)->where('centre_id',$slot->centre_id)->where('is_active',true)
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at','<=',now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at','>=',now()))->first();
            if (! $discount || $base < $discount->minimum_amount) throw ValidationException::withMessages(['discount_id'=>'تخفیف معتبر نیست.']);
        }
        $saving = $discount ? ($discount->type === 'percent' ? (int) round($base * min(100,$discount->value) / 100) : (int) $discount->value) : 0;
        $saving = min($base, $saving, $discount?->maximum_discount ?? PHP_INT_MAX);
        $final = $base - $saving;
        if ($paid < 0 || $paid > $final) throw ValidationException::withMessages(['paid_amount'=>'مبلغ پرداختی از مبلغ نهایی بیشتر است.']);
        return ['duration_minutes'=>$minutes,'unit_price_snapshot'=>$unitPrice,'base_price'=>$base,'final_price'=>$final,
            'paid_amount'=>$paid,'balance_amount'=>$final-$paid,'discount_id'=>$discount?->id,
            'discount_type_snapshot'=>$discount?->type,'discount_value_snapshot'=>$saving];
    }
}
