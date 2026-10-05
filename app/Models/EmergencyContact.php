<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyContact extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['client_id', 'full_name', 'relationship', 'phone', 'alternate_phone', 'priority', 'authorized_for_contact', 'notes'];
    protected function casts(): array { return ['authorized_for_contact' => 'boolean', 'priority' => 'integer']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
