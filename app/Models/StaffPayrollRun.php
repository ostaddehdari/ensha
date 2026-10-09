<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffPayrollRun extends Model
{
    protected $fillable=['public_id','run_number','centre_id','period_start','period_end','jalali_period','status','total_payable_amount','total_worked_minutes','staff_count','created_by','locked_by','locked_at','note'];
    protected function casts(): array { return ['period_start'=>'date','period_end'=>'date','locked_at'=>'datetime']; }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function locker(): BelongsTo { return $this->belongsTo(User::class,'locked_by'); }
    public function items(): HasMany { return $this->hasMany(StaffPayrollItem::class,'payroll_run_id'); }
}
