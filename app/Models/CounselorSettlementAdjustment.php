<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CounselorSettlementAdjustment extends Model
{
    protected $fillable=['settlement_id','kind','title','amount','note','created_by'];
    protected static function booted(): void { static::updating(fn()=>throw new LogicException('تعدیل تسویه قابل ویرایش نیست.')); static::deleting(fn()=>throw new LogicException('تعدیل تسویه قابل حذف نیست.')); }
    public function settlement(): BelongsTo { return $this->belongsTo(CounselorSettlement::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
}
