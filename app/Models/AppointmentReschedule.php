<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AppointmentReschedule extends Model { protected $fillable=['centre_id','from_appointment_id','to_appointment_id','client_id','reason','changed_by']; }
