<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrivateFile extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;
    protected $fillable = ['client_id', 'case_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'classification', 'scan_status', 'description'];
    protected function casts(): array { return ['size_bytes' => 'integer']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
