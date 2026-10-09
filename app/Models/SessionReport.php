<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SessionReport extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;

    protected $fillable = [
        'centre_id', 'appointment_id', 'counselling_session_id', 'case_id', 'client_id', 'counselor_id',
        'template_id', 'template_name_snapshot', 'template_version_snapshot', 'status', 'summary', 'outcome',
        'recommendations', 'structured_answers', 'follow_up_required', 'follow_up_at', 'content_hash',
        'finalized_by', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'encrypted',
            'outcome' => 'encrypted',
            'recommendations' => 'encrypted',
            'structured_answers' => 'encrypted:array',
            'follow_up_required' => 'boolean',
            'follow_up_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function session(): BelongsTo { return $this->belongsTo(CounsellingSession::class, 'counselling_session_id'); }
    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function template(): BelongsTo { return $this->belongsTo(SessionReportTemplate::class, 'template_id'); }
    public function finalizer(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by'); }
    public function addenda(): HasMany { return $this->hasMany(SessionReportAddendum::class); }
    public function isFinalized(): bool { return $this->status === 'finalized'; }
}
