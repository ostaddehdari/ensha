<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileValue extends Model
{
    protected $fillable = ['user_id', 'profile_field_id', 'value'];

    public function field()
    {
        return $this->belongsTo(ProfileField::class, 'profile_field_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
