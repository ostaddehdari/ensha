<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseAssignment extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory;

    protected $fillable = ['case_id', 'user_id', 'assignment_role', 'is_primary', 'status', 'starts_at', 'ends_at', 'notes'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'starts_at' => 'date', 'ends_at' => 'date'];
    }

    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
