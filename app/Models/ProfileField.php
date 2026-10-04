<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileField extends Model
{
    protected $fillable = ['role', 'label', 'key', 'field_type', 'is_required', 'options', 'sort_order', 'is_active', 'settings'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean', 'options' => 'array', 'settings' => 'array'];
    }

    public function values()
    {
        return $this->hasMany(ProfileValue::class);
    }
}
