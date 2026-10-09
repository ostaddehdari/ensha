<?php

return [
    'lock_store' => env('APPOINTMENT_LOCK_STORE', 'redis'),
    'resource_lock_seconds' => (int) env('APPOINTMENT_RESOURCE_LOCK_SECONDS', 30),
    'resource_lock_wait_seconds' => (int) env('APPOINTMENT_RESOURCE_LOCK_WAIT_SECONDS', 8),
];
