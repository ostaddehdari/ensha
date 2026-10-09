<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentreIntegration extends Model
{
    protected $fillable=['centre_id','driver','is_active','settings','secret_payload','last_checked_at','last_status','last_error'];
    protected function casts(): array { return ['is_active'=>'boolean','settings'=>'array','secret_payload'=>'encrypted:array','last_checked_at'=>'datetime']; }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function secret(string $key, mixed $default=null): mixed { return data_get($this->secret_payload?:[],$key,$default); }
}
