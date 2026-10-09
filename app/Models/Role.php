<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'scope', 'color', 'sort_order', 'is_system', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAssignableBy(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        $permissionIds = $actor->assignedRole?->permissions()
            ->where('permissions.is_active', true)
            ->pluck('permissions.id')
            ->all() ?? [];

        return $query
            ->where('scope', 'centre')
            ->where(function (Builder $builder) use ($permissionIds) {
                $builder->whereIn('slug', ['secretary', 'finance', 'counselor', 'test_manager', 'client'])
                    ->orWhere(function (Builder $custom) use ($permissionIds) {
                        $custom->where('is_system', false)
                            ->whereDoesntHave('permissions', function (Builder $permission) use ($permissionIds) {
                                $permission->whereNotIn('permissions.id', $permissionIds ?: [0]);
                            });
                    });
            });
    }
}
