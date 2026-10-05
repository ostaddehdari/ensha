<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientGuardian extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['client_id', 'full_name', 'relationship', 'phone', 'national_id', 'is_primary', 'has_legal_authority', 'verification_notes', 'verified_by', 'verified_at'];
    protected function casts(): array { return ['is_primary' => 'boolean', 'has_legal_authority' => 'boolean', 'verified_at' => 'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
}
