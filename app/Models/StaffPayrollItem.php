<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StaffPayrollItem extends Model
{
    protected $fillable=['payroll_run_id','staff_id','pay_rule_id','rule_snapshot','worked_minutes','worked_days','late_minutes','early_minutes','overtime_minutes','shortfall_minutes','base_pay_amount','hourly_pay_amount','overtime_pay_amount','payable_amount'];
    protected function casts(): array { return ['rule_snapshot'=>'array']; }
    protected static function booted(): void { static::updating(fn()=>throw new LogicException('قلم حقوق Snapshot شده و قابل ویرایش نیست.')); static::deleting(fn()=>throw new LogicException('قلم حقوق قفل‌شده قابل حذف نیست.')); }
    public function run(): BelongsTo { return $this->belongsTo(StaffPayrollRun::class,'payroll_run_id'); }
    public function staff(): BelongsTo { return $this->belongsTo(User::class,'staff_id'); }
}
