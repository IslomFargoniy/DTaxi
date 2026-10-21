<?php

namespace App\Services\Drivers;

use App\Enums\DriverStatus;
use App\Events\DriverLocationUpdated;
use App\Models\Driver;
use App\Support\Settings;

/**
 * Telefoni o'chgan yoki internetsiz qolgan "bo'sh" haydovchi xaritada bo'sh bo'lib turmasin va operator
 * unga tayinlamasin: N daqiqa GPS kelmasa — oflayn (smena oxirgi GPS vaqti bilan yopiladi).
 */
class DriverHeartbeat
{
    public function __construct(
        private Settings $settings,
        private ShiftTracker $shifts,
        private StandQueue $queue,
    ) {}

    public function markSilentOffline(): int
    {
        $count = 0;
        $branchIds = Driver::query()->withoutGlobalScopes()
            ->where('status', DriverStatus::Free->value)
            ->distinct()->pluck('branch_id');

        foreach ($branchIds as $branchId) {
            $minutes = (int) $this->settings->get('drivers.offline_after_minutes', (int) $branchId);
            if ($minutes <= 0) {
                continue;
            }
            $cutoff = now()->subMinutes($minutes);

            $silent = Driver::query()->withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('status', DriverStatus::Free->value)
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
                ->get();

            foreach ($silent as $driver) {
                // Shartli: shu orada GPS kelgan yoki buyurtma olgan bo'lsa tegilmaydi
                $updated = Driver::query()->withoutGlobalScopes()->whereKey($driver->id)
                    ->where('status', DriverStatus::Free->value)
                    ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
                    ->update(['status' => DriverStatus::Offline->value]);
                if ($updated !== 1) {
                    continue;
                }

                $driver->refresh();
                $this->shifts->end($driver, $driver->last_seen_at);
                $this->queue->leave($driver);
                DriverLocationUpdated::dispatch($driver);
                $count++;
            }
        }

        return $count;
    }
}
