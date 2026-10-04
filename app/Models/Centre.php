<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Centre extends Model
{
    protected $fillable = ['name', 'code', 'is_active', 'phone', 'email', 'address', 'description', 'timezone', 'settings'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(CentreBranch::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleCentre::class);
    }
}
