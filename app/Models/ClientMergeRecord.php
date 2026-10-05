<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMergeRecord extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['source_client_id', 'target_client_id', 'requested_by', 'approved_by', 'status', 'reason', 'merge_summary', 'executed_at'];
    protected function casts(): array { return ['merge_summary' => 'array', 'executed_at' => 'datetime']; }
    public function source(): BelongsTo { return $this->belongsTo(Client::class, 'source_client_id'); }
    public function target(): BelongsTo { return $this->belongsTo(Client::class, 'target_client_id'); }
}
