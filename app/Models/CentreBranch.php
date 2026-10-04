<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentreBranch extends Model
{
    protected $fillable = [
        'centre_id', 'name', 'code', 'timezone', 'phone', 'email', 'address',
        'is_default', 'is_active', 'settings',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'settings' => 'array'];
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleCentre::class, 'branch_id');
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone ?: $this->centre?->timezone ?: 'Asia/Tehran';
    }
}
