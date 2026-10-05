<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSlot extends Model
{
    protected $fillable = ['centre_id', 'branch_id', 'topic_id', 'counselor_id', 'room_id', 'slot_date', 'starts_at', 'ends_at', 'mode', 'capacity', 'booked_count', 'status', 'lock_version', 'source'];
    protected function casts(): array { return ['slot_date' => 'date', 'starts_at' => 'datetime', 'ends_at' => 'datetime']; }
    public function topic(): BelongsTo { return $this->belongsTo(ServiceTopic::class, 'topic_id'); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function room(): BelongsTo { return $this->belongsTo(CentreRoom::class, 'room_id'); }
    public function appointments(): HasMany { return $this->hasMany(Appointment::class, 'slot_id'); }
}
