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

    protected $fillable = ['appointment_number', 'centre_id', 'branch_id', 'client_id', 'case_id', 'topic_id', 'counselor_id', 'room_id', 'slot_id', 'tariff_id', 'seat_number', 'mode', 'starts_at', 'ends_at', 'status', 'price', 'counselor_pay', 'currency', 'notes', 'cancellation_reason', 'created_by'];
    protected function casts(): array { return ['starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function case(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function topic(): BelongsTo { return $this->belongsTo(ServiceTopic::class, 'topic_id'); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function slot(): BelongsTo { return $this->belongsTo(AppointmentSlot::class, 'slot_id'); }
    public function tariff(): BelongsTo { return $this->belongsTo(ServiceTariff::class, 'tariff_id'); }
    public function histories(): HasMany { return $this->hasMany(AppointmentStatusHistory::class); }
    public function canTransitionTo(string $status): bool { return in_array($status, self::TRANSITIONS[$this->status] ?? [], true); }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) return $query;
        if ($actor->hasPermission('appointments.manage')) return $query->where('centre_id', $actor->centre_id);
        if ($actor->assignedRole?->slug === 'counselor') return $query->where('counselor_id', $actor->id);
        if ($actor->assignedRole?->slug === 'client') return $query->whereHas('client', fn (Builder $q) => $q->where('user_id', $actor->id));
        return $query->whereRaw('1 = 0');
    }
}
