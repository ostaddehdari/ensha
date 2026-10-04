<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class UserRoleCentre extends Model {
 protected $fillable=['user_id','role_id','centre_id','branch_id'];
 public function role(){return $this->belongsTo(Role::class);}
 public function centre(){return $this->belongsTo(Centre::class);}
 public function branch(){return $this->belongsTo(CentreBranch::class,'branch_id');}
 public function user(){return $this->belongsTo(User::class);}
}
