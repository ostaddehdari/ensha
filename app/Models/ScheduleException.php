<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleException extends Model
{
    protected $fillable = ['centre_id', 'branch_id', 'user_id', 'exception_date', 'starts_at', 'ends_at', 'type', 'reason', 'created_by'];
    protected function casts(): array { return ['exception_date' => 'date']; }
}
