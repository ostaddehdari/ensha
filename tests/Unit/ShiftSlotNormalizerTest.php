<?php

namespace Tests\Unit;

use App\Support\ShiftSlotNormalizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ShiftSlotNormalizerTest extends TestCase
{
    public function test_it_merges_only_contiguous_half_hour_slots(): void
    {
        $this->assertSame([
            ['starts_at' => '08:00', 'ends_at' => '09:00'],
            ['starts_at' => '11:00', 'ends_at' => '11:30'],
        ], ShiftSlotNormalizer::normalize(['11:00', '08:30', '08:00']));
    }

    public function test_it_removes_duplicate_slots(): void
    {
        $this->assertSame([
            ['starts_at' => '09:00', 'ends_at' => '09:30'],
        ], ShiftSlotNormalizer::normalize(['09:00', '09:00']));
    }

    public function test_it_rejects_invalid_slot_values(): void
    {
        $this->expectException(ValidationException::class);

        ShiftSlotNormalizer::normalize(['08:15']);
    }
}
