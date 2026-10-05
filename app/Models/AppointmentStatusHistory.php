<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentStatusHistory extends Model
{
    protected $fillable = ['appointment_id', 'from_status', 'to_status', 'changed_by', 'reason', 'metadata', 'changed_at'];
    protected function casts(): array { return ['metadata' => 'array', 'changed_at' => 'datetime']; }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
