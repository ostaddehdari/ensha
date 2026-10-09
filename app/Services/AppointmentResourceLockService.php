<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AppointmentResourceLockService
{
    /**
     * Run a calendar mutation while holding all resource locks in a stable order.
     * Stable ordering prevents two reschedules from deadlocking each other.
     */
    public function block(array $resourceKeys, Closure $callback): mixed
    {
        $keys = collect($resourceKeys)
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->unique()->sort()->values()->all();

        try {
            return $this->acquire($keys, 0, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'schedule' => 'عملیات هم‌زمان دیگری روی این مشاور یا اتاق در جریان است؛ دوباره تلاش کنید.',
            ]);
        }
    }

    public function keys(
        int $centreId,
        array $counselorIds = [],
        array $slotIds = [],
        bool $usesRooms = false,
    ): array {
        $keys = [];
        foreach ($counselorIds as $id) {
            if ((int) $id > 0) {
                $keys[] = 'counselor:'.(int) $id;
            }
        }
        foreach ($slotIds as $id) {
            if ((int) $id > 0) {
                $keys[] = 'slot:'.(int) $id;
            }
        }
        if ($usesRooms) {
            $keys[] = 'room-pool:'.$centreId;
        }

        return $keys;
    }

    private function acquire(array $keys, int $offset, Closure $callback): mixed
    {
        if (! isset($keys[$offset])) {
            return $callback();
        }

        $store = Cache::store(config('appointments.lock_store', 'redis'));
        $seconds = (int) config('appointments.resource_lock_seconds', 30);
        $wait = (int) config('appointments.resource_lock_wait_seconds', 8);

        return $store->lock('ensha:schedule:'.$keys[$offset], $seconds)
            ->block($wait, fn () => $this->acquire($keys, $offset + 1, $callback));
    }
}
