<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TelephoneConsultation extends Model { protected $fillable=['centre_id','client_id','appointment_id','counselor_id','phone_number','started_at','ended_at','status','outcome','counselor_note','created_by']; protected function casts():array{return ['started_at'=>'datetime','ended_at'=>'datetime'];} public function client():BelongsTo{return $this->belongsTo(Client::class);} public function counselor():BelongsTo{return $this->belongsTo(User::class,'counselor_id');} public function appointment():BelongsTo{return $this->belongsTo(Appointment::class);} }
