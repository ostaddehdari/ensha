<?php

namespace App\Services;

use App\Models\ServiceTariff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TariffVersionService
{
    public function create(array $data): ServiceTariff
    {
        return DB::transaction(function () use ($data) {
            if (! DB::table('centres')->where('id', $data['centre_id'])->lockForUpdate()->first()) {
                throw new \InvalidArgumentException('مرکز معتبر نیست.');
            }
            $data['scope_key'] = 'b:'.($data['branch_id'] ?? '*').':c:'.($data['counselor_id'] ?? '*');
            $scope = ServiceTariff::query()
                ->where('centre_id', $data['centre_id'])
                ->where('topic_id', $data['topic_id'])
                ->where('scope_key', $data['scope_key']);

            $latest = (clone $scope)->lockForUpdate()->orderByDesc('version')->first();
            $validFrom = CarbonImmutable::parse($data['valid_from'])->startOfDay();
            if ($latest && $latest->valid_from->greaterThanOrEqualTo($validFrom)) {
                throw new \InvalidArgumentException('تاریخ شروع نسخه جدید باید بعد از نسخه قبلی باشد.');
            }
            if ($latest && $latest->is_active) {
                $latest->update(['valid_until' => $validFrom->subDay()->toDateString(), 'is_active' => false]);
            }

            return ServiceTariff::create($data + ['version' => ($latest?->version ?? 0) + 1, 'is_active' => true]);
        });
    }
}
