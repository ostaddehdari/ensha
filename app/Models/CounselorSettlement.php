<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CounselorSettlement extends Model
{
    protected $fillable=['public_id','settlement_number','centre_id','counselor_id','period_start','period_end','status','gross_collected_amount','centre_share_amount','counselor_share_amount','deductions_amount','bonuses_amount','payable_amount','paid_amount','currency','note','created_by','approved_by','approved_at','paid_by','paid_at','payment_reference','cancelled_by','cancelled_at','cancellation_reason'];
    protected function casts(): array { return ['period_start'=>'date','period_end'=>'date','approved_at'=>'datetime','paid_at'=>'datetime','cancelled_at'=>'datetime']; }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class,'counselor_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class,'approved_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class,'paid_by'); }
    public function items(): HasMany { return $this->hasMany(CounselorSettlementItem::class,'settlement_id'); }
    public function adjustments(): HasMany { return $this->hasMany(CounselorSettlementAdjustment::class,'settlement_id'); }
}
