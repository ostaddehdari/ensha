<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationRule extends Model
{
    protected $fillable=['public_id','centre_id','topic_id','counselor_id','name','beneficiary','calculation_type','value','version','valid_from','valid_until','is_active','note','created_by'];
    protected function casts(): array { return ['valid_from'=>'date','valid_until'=>'date','is_active'=>'boolean']; }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function topic(): BelongsTo { return $this->belongsTo(ServiceTopic::class); }
    public function counselor(): BelongsTo { return $this->belongsTo(User::class,'counselor_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function scopeEffectiveOn(Builder $query,string $date): Builder { return $query->where('is_active',true)->whereDate('valid_from','<=',$date)->where(fn($q)=>$q->whereNull('valid_until')->orWhereDate('valid_until','>=',$date)); }
}
