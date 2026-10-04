<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentity extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'provider', 'external_id', 'external_username',
        'external_email', 'metadata', 'linked_at', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'linked_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
