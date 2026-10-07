<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BookingPolicy
{
    public static function forCentre(int $centreId): object
    {
        return DB::table('centre_booking_policies')->where('centre_id', $centreId)->first()
            ?? (object) ['check_rooms' => false, 'allow_past_bookings' => false];
    }
}
