<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConfidentialNote extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;
    protected $fillable = ['case_id', 'session_id', 'author_id', 'body', 'status', 'content_hash', 'finalized_by', 'finalized_at'];
    protected function casts(): array { return ['body' => 'encrypted', 'finalized_at' => 'datetime']; }
    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function session(): BelongsTo { return $this->belongsTo(CounsellingSession::class, 'session_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function finalizer(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by'); }
    public function addenda(): HasMany { return $this->hasMany(NoteAddendum::class); }
    public function isFinalized(): bool { return $this->status === 'finalized'; }
}
