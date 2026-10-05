<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientConsent extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['client_id', 'case_id', 'consent_type', 'document_version', 'is_granted', 'granted_at', 'revoked_at', 'expires_at', 'captured_by', 'evidence', 'notes'];
    protected function casts(): array { return ['is_granted' => 'boolean', 'granted_at' => 'datetime', 'revoked_at' => 'datetime', 'expires_at' => 'date', 'evidence' => 'array']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function capturer(): BelongsTo { return $this->belongsTo(User::class, 'captured_by'); }
}
