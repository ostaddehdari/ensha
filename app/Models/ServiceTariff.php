<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTariff extends Model
{
    protected $fillable = ['centre_id', 'branch_id', 'topic_id', 'counselor_id', 'scope_key', 'version', 'price', 'counselor_pay', 'currency', 'valid_from', 'valid_until', 'is_active', 'created_by'];
    protected function casts(): array { return ['valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean']; }
    public function topic(): BelongsTo { return $this->belongsTo(ServiceTopic::class, 'topic_id'); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class, 'counselor_id'); }
    public function branch(): BelongsTo { return $this->belongsTo(CentreBranch::class, 'branch_id'); }
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date));
    }
}
