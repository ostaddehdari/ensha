<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayrollRunAudit extends Model
{
    protected $fillable=['payroll_run_id','actor_id','action','from_status','to_status','reason','metadata'];
    protected function casts(): array { return ['metadata'=>'array']; }
    public function run(): BelongsTo { return $this->belongsTo(StaffPayrollRun::class,'payroll_run_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class,'actor_id'); }
}
