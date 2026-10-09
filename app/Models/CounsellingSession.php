<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CounsellingSession extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['appointment_id', 'case_id', 'counselor_id', 'session_number', 'scheduled_at', 'started_at', 'ended_at', 'duration_minutes', 'channel', 'status', 'administrative_summary', 'created_by'];
    protected function casts(): array { return ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'duration_minutes' => 'integer']; }
    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function notes(): HasMany { return $this->hasMany(ConfidentialNote::class, 'session_id'); }
    public function report(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(SessionReport::class, 'counselling_session_id'); }
    public function recordings(): HasMany { return $this->hasMany(SessionRecording::class, 'counselling_session_id'); }
}
