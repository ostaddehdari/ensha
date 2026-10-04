<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class ShiftSlotNormalizer
{
    /**
     * Convert selected half-hour slots to one or more contiguous time ranges.
     *
     * @return array<int, array{starts_at: string, ends_at: string}>
     */
    public static function normalize(array $slots): array
    {
        $minutes = [];

        foreach ($slots as $slot) {
            if (! is_string($slot) || ! preg_match('/^(?:[01]\d|2[0-3]):(?:00|30)$/', $slot)) {
                throw ValidationException::withMessages([
                    'slots' => 'یکی از بازه‌های انتخاب‌شده معتبر نیست.',
                ]);
            }

            [$hour, $minute] = array_map('intval', explode(':', $slot));
            $value = ($hour * 60) + $minute;

            if ($value < 360 || $value >= 1320) {
                throw ValidationException::withMessages([
                    'slots' => 'ساعات کاری باید بین 06:00 تا 22:00 باشند.',
                ]);
            }

            $minutes[$value] = true;
        }

        $minutes = array_keys($minutes);
        sort($minutes, SORT_NUMERIC);

        if ($minutes === []) {
            throw ValidationException::withMessages([
                'slots' => 'حداقل یک بازه نیم‌ساعته انتخاب کنید.',
            ]);
        }

        $ranges = [];
        $start = $previous = $minutes[0];

        foreach (array_slice($minutes, 1) as $minute) {
            if ($minute !== $previous + 30) {
                $ranges[] = self::range($start, $previous + 30);
                $start = $minute;
            }

            $previous = $minute;
        }

        $ranges[] = self::range($start, $previous + 30);

        return $ranges;
    }

    /** @return array{starts_at: string, ends_at: string} */
    private static function range(int $start, int $end): array
    {
        return [
            'starts_at' => self::time($start),
            'ends_at' => self::time($end),
        ];
    }

    private static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
