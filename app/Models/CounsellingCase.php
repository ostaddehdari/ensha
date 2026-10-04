<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingCase extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cases';
    protected $fillable = [
        'client_id', 'centre_id', 'case_number', 'title', 'status', 'priority',
        'opened_at', 'closed_at', 'presenting_issue', 'administrative_notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'date', 'closed_at' => 'date'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignments(): HasMany { return $this->hasMany(CaseAssignment::class, 'case_id'); }
    public function statusHistories(): HasMany { return $this->hasMany(CaseStatusHistory::class, 'case_id'); }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) return $query;
        if ($actor->hasPermission('cases.assign') || $actor->hasPermission('cases.manage')) {
            return $query->where('centre_id', $actor->centre_id);
        }
        return $query->where('centre_id', $actor->centre_id)
            ->whereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $actor->id)->where('status', 'active'));
    }
}
