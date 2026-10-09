<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CounselorSettlementItem extends Model
{
    protected $fillable=['settlement_id','appointment_id','compensation_snapshot_id','collected_amount','centre_share_amount','counselor_share_amount','calculation_snapshot'];
    protected function casts(): array { return ['calculation_snapshot'=>'array']; }
    protected static function booted(): void { static::updating(fn()=>throw new LogicException('قلم تسویه قابل ویرایش نیست.')); static::deleting(fn()=>throw new LogicException('قلم تسویه قابل حذف نیست.')); }
    public function settlement(): BelongsTo { return $this->belongsTo(CounselorSettlement::class); }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function compensationSnapshot(): BelongsTo { return $this->belongsTo(AppointmentCompensationSnapshot::class); }
}
