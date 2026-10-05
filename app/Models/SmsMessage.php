<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SmsMessage extends Model { protected $fillable=['centre_id','client_id','appointment_id','phone_number','type','body','scheduled_for','sent_at','status','provider_response','created_by']; protected function casts():array{return ['scheduled_for'=>'datetime','sent_at'=>'datetime'];} }
