<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoteAddendum extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['confidential_note_id', 'author_id', 'body', 'content_hash'];
    protected function casts(): array { return ['body' => 'encrypted']; }
    public function note(): BelongsTo { return $this->belongsTo(ConfidentialNote::class, 'confidential_note_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
