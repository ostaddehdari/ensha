<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTopic extends Model
{
    protected $fillable = ['category_id', 'name', 'minimum_minutes', 'session_minutes', 'break_minutes', 'capacity', 'requires_room', 'is_active', 'price', 'color', 'allowed_modes'];

    protected function casts(): array
    {
        return ['allowed_modes' => 'array', 'requires_room' => 'boolean', 'is_active' => 'boolean'];
    }

    public function category(): BelongsTo { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function tariffs(): HasMany { return $this->hasMany(ServiceTariff::class, 'topic_id'); }
    public function slots(): HasMany { return $this->hasMany(AppointmentSlot::class, 'topic_id'); }
}
