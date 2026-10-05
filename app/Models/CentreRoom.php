<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentreRoom extends Model
{
    protected $fillable = ['centre_id', 'branch_id', 'name', 'capacity', 'room_type', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
