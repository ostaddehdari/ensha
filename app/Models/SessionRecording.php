<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SessionRecording extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'public_id', 'centre_id', 'appointment_id', 'counselling_session_id', 'client_id', 'counselor_id',
        'consent_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256',
        'plaintext_sha256', 'duration_seconds', 'status', 'chunk_count', 'received_chunks',
        'transcript_status', 'transcript_text', 'transcript_language', 'transcription_provider',
        'transcription_error', 'transcribed_at', 'transcript_finalized_by', 'transcript_finalized_at',
        'transcript_hash', 'consent_snapshot', 'recorded_at', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'chunk_count' => 'integer',
            'received_chunks' => 'integer',
            'transcript_text' => 'encrypted',
            'transcription_error' => 'encrypted',
            'consent_snapshot' => 'array',
            'transcribed_at' => 'datetime',
            'transcript_finalized_at' => 'datetime',
            'recorded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string { return 'public_id'; }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function session(): BelongsTo { return $this->belongsTo(CounsellingSession::class, 'counselling_session_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function consent(): BelongsTo { return $this->belongsTo(ClientConsent::class, 'consent_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function transcriptFinalizer(): BelongsTo { return $this->belongsTo(User::class, 'transcript_finalized_by'); }
    public function isReady(): bool { return $this->status === 'ready' && filled($this->path); }
    public function transcriptIsFinalized(): bool { return $this->transcript_status === 'finalized'; }
}
