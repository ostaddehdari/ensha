<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StaffProfile extends Model {
 protected $fillable = ['job_title','employment_type','start_date','end_date','license_number','specialty','internal_notes','employee_code','department','work_email','extension'];
 protected function casts(): array { return ['start_date'=>'date','end_date'=>'date']; }
 public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
