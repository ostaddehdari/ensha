<?php

namespace App\Services;

use App\Models\AppointmentSlot;
use App\Models\ServiceTopic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SlotGenerator
{
    public function generate(int $centreId, int $branchId, int $topicId, int $counselorId, string $from, string $to, string $mode): int
    {
        $topic = ServiceTopic::query()
            ->whereKey($topicId)
            ->whereHas('category', fn ($q) => $q->where('centre_id', $centreId))
            ->where('is_active', true)->firstOrFail();
        abort_unless(in_array($mode, $topic->allowed_modes ?: ['in_person'], true), 422, 'شیوه ارائه برای این خدمت فعال نیست.');
        abort_unless(DB::table('counselor_topics')->where('user_id', $counselorId)->where('topic_id', $topicId)->exists(), 422, 'این خدمت به مشاور تخصیص ندارد.');

        $startDate = CarbonImmutable::parse($from)->startOfDay();
        $endDate = CarbonImmutable::parse($to)->startOfDay();
        abort_if($endDate->lt($startDate) || $startDate->diffInDays($endDate) > 62, 422, 'بازه تولید اسلات باید حداکثر ۶۲ روز باشد.');

        return DB::transaction(function () use ($centreId, $branchId, $topic, $counselorId, $startDate, $endDate, $mode) {
            abort_unless(DB::table('users')->where('id', $counselorId)->lockForUpdate()->first(), 404);
            DB::table('centre_rooms')->where('centre_id', $centreId)->orderBy('id')->lockForUpdate()->get();
            $created = 0;
            for ($date = $startDate; $date->lte($endDate); $date = $date->addDay()) {
                if ($this->closed($centreId, $counselorId, $date)) continue;
                foreach ($this->windows($centreId, $branchId, $counselorId, $date) as $window) {
                    $cursor = $date->setTimeFromTimeString($window->starts_at);
                    $windowEnd = $date->setTimeFromTimeString($window->ends_at);
                    $step = max(5, (int) ($window->slot_interval_minutes ?? 15));
                    while ($cursor->addMinutes($topic->session_minutes)->lte($windowEnd)) {
                        $endsAt = $cursor->addMinutes($topic->session_minutes);
                        if (! $this->unavailable($centreId, $counselorId, $date, $cursor, $endsAt)) {
                            $roomId = $mode === 'in_person' && $topic->requires_room
                                ? $this->room($centreId, $branchId, $topic->id, $cursor, $endsAt)
                                : null;
                            if ($mode !== 'in_person' || ! $topic->requires_room || $roomId) {
                                $slot = AppointmentSlot::firstOrCreate([
                                    'counselor_id' => $counselorId, 'topic_id' => $topic->id,
                                    'starts_at' => $cursor, 'mode' => $mode,
                                ], [
                                    'centre_id' => $centreId, 'branch_id' => $branchId, 'room_id' => $roomId,
                                    'slot_date' => $date->toDateString(), 'ends_at' => $endsAt,
                                    'capacity' => max(1, (int) $topic->capacity), 'booked_count' => 0,
                                    'status' => 'available', 'source' => $window->source,
                                ]);
                                $created += $slot->wasRecentlyCreated ? 1 : 0;
                            }
                        }
                        $cursor = $cursor->addMinutes(max($step, $topic->session_minutes + (int) $topic->break_minutes));
                    }
                }
            }
            return $created;
        }, 3);
    }

    private function windows(int $centreId, int $branchId, int $userId, CarbonImmutable $date): array
    {
        $overrides = DB::table('schedule_exceptions')->where('centre_id', $centreId)->where('user_id', $userId)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->whereDate('exception_date', $date)->where('type', 'override')->whereNotNull('starts_at')->get()
            ->map(fn ($row) => (object) ['starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'slot_interval_minutes' => 15, 'source' => 'schedule_exception'])->all();
        if ($overrides) return $overrides;
        return DB::table('counselor_shifts')->where('centre_id', $centreId)->where('user_id', $userId)
            ->where('weekday', $date->dayOfWeek)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->get()->map(fn ($row) => (object) [...(array) $row, 'source' => 'weekly_schedule'])->all();
    }

    private function closed(int $centreId, int $userId, CarbonImmutable $date): bool
    {
        if (DB::table('official_holidays')->where('is_active', true)->whereDate('gregorian_date', $date)->exists()) return true;
        if (DB::table('centre_closures')->where('centre_id', $centreId)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) return true;
        return DB::table('counselor_leaves')->where('centre_id', $centreId)->where('user_id', $userId)
            ->where('starts_at', '<', $date->endOfDay())->where('ends_at', '>', $date->startOfDay())->exists();
    }

    private function unavailable(int $centreId, int $userId, CarbonImmutable $date, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return DB::table('schedule_exceptions')->where('centre_id', $centreId)->where('user_id', $userId)
            ->whereDate('exception_date', $date)->where('type', 'unavailable')
            ->where(function ($q) use ($start, $end) {
                $q->whereNull('starts_at')->orWhere(fn ($timed) => $timed->where('starts_at', '<', $end->format('H:i:s'))->where('ends_at', '>', $start->format('H:i:s')));
            })->exists();
    }

    private function room(int $centreId, int $branchId, int $topicId, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $rooms = DB::table('centre_rooms as r')->leftJoin('room_topics as rt', 'rt.room_id', '=', 'r.id')
            ->where('r.centre_id', $centreId)->where('r.is_active', true)
            ->where(fn ($q) => $q->whereNull('r.branch_id')->orWhere('r.branch_id', $branchId))
            ->where(fn ($q) => $q->whereNull('rt.topic_id')->orWhere('rt.topic_id', $topicId))
            ->orderByDesc('r.capacity')->pluck('r.id')->unique();
        foreach ($rooms as $roomId) {
            $busy = AppointmentSlot::where('room_id', $roomId)->where('status', '!=', 'blocked')
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if (! $busy) return (int) $roomId;
        }
        return null;
    }
}
