<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionReportAddendum extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['session_report_id', 'author_id', 'body', 'content_hash'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    public function report(): BelongsTo { return $this->belongsTo(SessionReport::class, 'session_report_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
