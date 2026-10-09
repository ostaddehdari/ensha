<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'arrived', 'in_session', 'completed', 'cancelled', 'no_show'];
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['arrived', 'cancelled', 'no_show'],
        'arrived' => ['in_session', 'cancelled', 'no_show'],
        'in_session' => ['completed', 'cancelled'],
        'completed' => [], 'cancelled' => [], 'no_show' => [],
    ];

    protected $fillable = ['appointment_number', 'centre_id', 'branch_id', 'client_id', 'case_id', 'topic_id', 'counselor_id', 'room_id', 'slot_id', 'tariff_id', 'seat_number', 'mode', 'starts_at', 'ends_at', 'status', 'price', 'counselor_pay', 'currency', 'notes', 'cancellation_reason', 'created_by', 'public_id','source','duration_minutes','base_price','final_price','paid_amount','balance_amount','payment_status','financial_updated_at','discount_id','discount_value_snapshot','discount_type_snapshot','topic_name_snapshot','topic_color_snapshot','unit_price_snapshot','status_id','status_snapshot','payment_note','updated_by','checked_in_at','checked_in_by','session_started_at','session_ended_at','no_show_at'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'checked_in_at' => 'datetime', 'session_started_at' => 'datetime', 'session_ended_at' => 'datetime', 'no_show_at' => 'datetime', 'financial_updated_at' => 'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function case(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function topic(): BelongsTo { return $this->belongsTo(ServiceTopic::class, 'topic_id'); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function slot(): BelongsTo { return $this->belongsTo(AppointmentSlot::class, 'slot_id'); }
    public function tariff(): BelongsTo { return $this->belongsTo(ServiceTariff::class, 'tariff_id'); }
    public function histories(): HasMany { return $this->hasMany(AppointmentStatusHistory::class); }
    public function counsellingSession(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(CounsellingSession::class); }
    public function sessionReport(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(SessionReport::class); }
    public function recordings(): HasMany { return $this->hasMany(SessionRecording::class); }
    public function paymentTransactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
    public function canTransitionTo(string $status): bool {
        if (in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) return true;
        $current=\Illuminate\Support\Facades\DB::table('appointment_statuses')->where('centre_id',$this->centre_id)->where('slug',$this->status)->first();
        return $status !== $this->status && ! ($current?->is_final ?? in_array($this->status,['completed','cancelled','no_show'],true))
            && \Illuminate\Support\Facades\DB::table('appointment_statuses')->where('centre_id',$this->centre_id)->where('slug',$status)->where('is_active',true)->exists();
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) return $query;
        if ($actor->hasPermission('appointments.manage')) return $query->where('centre_id', $actor->centre_id);
        if ($actor->hasPermission('payments.view')) return $query->where('centre_id', $actor->centre_id);
        if ($actor->assignedRole?->slug === 'counselor') return $query->where('counselor_id', $actor->id);
        if ($actor->assignedRole?->slug === 'client') return $query->whereHas('client', fn (Builder $q) => $q->where('user_id', $actor->id));
        return $query->whereRaw('1 = 0');
    }
}
