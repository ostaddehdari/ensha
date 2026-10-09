<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AppointmentCompensationSnapshot extends Model
{
    protected $fillable=['public_id','appointment_id','centre_id','counselor_id','topic_id','rule_id','rule_snapshot','gross_amount','discount_amount','collected_at_snapshot','outstanding_at_snapshot','centre_share_amount','counselor_share_amount','currency','calculated_at','calculated_by','locked_at'];
    protected function casts(): array { return ['rule_snapshot'=>'array','calculated_at'=>'datetime','locked_at'=>'datetime']; }
    protected static function booted(): void { static::updating(fn()=>throw new LogicException('Snapshot سهم جلسه قابل ویرایش نیست.')); static::deleting(fn()=>throw new LogicException('Snapshot سهم جلسه قابل حذف نیست.')); }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function rule(): BelongsTo { return $this->belongsTo(CompensationRule::class); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class,'counselor_id'); }
    public function settlementItems(): HasMany { return $this->hasMany(CounselorSettlementItem::class,'compensation_snapshot_id'); }
}
