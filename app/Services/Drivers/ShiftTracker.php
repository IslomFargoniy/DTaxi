<?php

namespace App\Services\Drivers;

use App\Enums\OrderStatus;
use App\Enums\TransactionType;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\DriverTransaction;
use App\Models\Order;

/**
 * Smena: liniyaga chiqishdan chiqishgacha. Telefon o'chib qolsa smena ochiq qoladi — keyingi chiqishda
 * oxirgi GPS vaqti bilan yopiladi.
 */
class ShiftTracker
{
    public function start(Driver $driver): DriverShift
    {
        $open = $this->open($driver);
        if ($open) {
            if ($open->started_at->gt(now()->subHours(16))) {
                return $open;
            }
            $open->forceFill(['ended_at' => $driver->last_seen_at ?? now()])->save();
        }

        return DriverShift::query()->create(['driver_id' => $driver->id, 'branch_id' => $driver->branch_id, 'started_at' => now()]);
    }

    public function end(Driver $driver, ?\DateTimeInterface $at = null): void
    {
        $this->open($driver)?->forceFill(['ended_at' => $at ?? now()])->save();
    }

    /**
     * @return array{started_at: string, ended_at: string|null, minutes: int, orders: int, earnings: int, commission: int}|null
     */
    public function summary(Driver $driver, ?DriverShift $shift = null): ?array
    {
        $shift ??= $this->open($driver) ?? DriverShift::query()->where('driver_id', $driver->id)->latest('started_at')->first();
        if (! $shift) {
            return null;
        }
        $to = $shift->ended_at ?? now();

        $orders = Order::query()->withoutGlobalScopes()
            ->where('driver_id', $driver->id)
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween('completed_at', [$shift->started_at, $to]);

        return [
            'started_at' => $shift->started_at->toIso8601String(),
            'ended_at' => $shift->ended_at?->toIso8601String(),
            'minutes' => (int) $shift->started_at->diffInMinutes($to),
            'orders' => (clone $orders)->count(),
            'earnings' => (int) (clone $orders)->sum('price_final'),
            'commission' => (int) -DriverTransaction::query()
                ->where('driver_id', $driver->id)
                ->where('type', TransactionType::Commission->value)
                ->whereBetween('created_at', [$shift->started_at, $to])
                ->sum('amount'),
        ];
    }

    private function open(Driver $driver): ?DriverShift
    {
        return DriverShift::query()->where('driver_id', $driver->id)->whereNull('ended_at')->latest('started_at')->first();
    }
}
