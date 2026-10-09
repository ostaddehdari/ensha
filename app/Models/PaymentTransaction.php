<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'public_id', 'transaction_number', 'centre_id', 'branch_id', 'appointment_id', 'client_id',
        'cash_register_session_id', 'parent_transaction_id', 'kind', 'method', 'amount', 'signed_amount',
        'currency', 'status', 'reference_number', 'idempotency_key', 'note', 'metadata', 'occurred_at', 'created_by',
    ];

    protected function casts(): array { return ['metadata' => 'array', 'occurred_at' => 'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('تراکنش مالی ثبت‌شده قابل ویرایش نیست.'));
        static::deleting(fn () => throw new LogicException('تراکنش مالی ثبت‌شده قابل حذف نیست.'));
    }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function registerSession(): BelongsTo { return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id'); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_transaction_id'); }
    public function reversals(): HasMany { return $this->hasMany(self::class, 'parent_transaction_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function receipt(): HasOne { return $this->hasOne(PaymentReceipt::class, 'transaction_id'); }
}
