<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseStatusHistory extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory;

    protected $fillable = ['case_id', 'from_status', 'to_status', 'changed_by', 'reason', 'metadata', 'changed_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'changed_at' => 'datetime'];
    }

    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function changer(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
