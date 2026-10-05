<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AppointmentWaitlist extends Model { protected $fillable=['centre_id','client_id','topic_id','counselor_id','desired_from','desired_until','priority','status','notes','created_by','promoted_at','promoted_appointment_id']; protected function casts():array{return ['desired_from'=>'datetime','desired_until'=>'datetime','promoted_at'=>'datetime'];} public function client():BelongsTo{return $this->belongsTo(Client::class);} public function topic():BelongsTo{return $this->belongsTo(ServiceTopic::class,'topic_id');} }
