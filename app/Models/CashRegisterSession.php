<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends Model
{
    protected $fillable = [
        'public_id', 'session_number', 'centre_id', 'branch_id', 'cashier_id', 'opened_by', 'opened_at',
        'opening_cash_amount', 'status', 'cash_payments_amount', 'cash_refunds_amount', 'non_cash_net_amount',
        'expected_cash_amount', 'counted_cash_amount', 'difference_amount', 'closed_by', 'closed_at',
        'opening_note', 'closing_note',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function branch(): BelongsTo { return $this->belongsTo(CentreBranch::class); }
    public function cashier(): BelongsTo { return $this->belongsTo(User::class, 'cashier_id'); }
    public function openedBy(): BelongsTo { return $this->belongsTo(User::class, 'opened_by'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function transactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
}
