<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentReceipt extends Model
{
    protected $fillable = ['public_id', 'receipt_number', 'transaction_id', 'snapshot', 'issued_at', 'issued_by'];
    protected function casts(): array { return ['snapshot' => 'encrypted:array', 'issued_at' => 'datetime']; }
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('رسید صادرشده قابل ویرایش نیست.'));
        static::deleting(fn () => throw new LogicException('رسید صادرشده قابل حذف نیست.'));
    }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function transaction(): BelongsTo { return $this->belongsTo(PaymentTransaction::class); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
}
