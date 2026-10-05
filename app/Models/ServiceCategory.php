<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    protected $fillable = ['centre_id', 'name'];
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function topics(): HasMany { return $this->hasMany(ServiceTopic::class, 'category_id'); }
}
