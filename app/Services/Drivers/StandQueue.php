<?php

namespace App\Services\Drivers;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Navbat joylari: haydovchi joylashuvi kelganda navbat yangilanadi (bo'sh va radius ichida — navbatda,
 * aks holda chiqadi). Kelish vaqti saqlanadi: haydovchi joyida turaversa o'rni yo'qolmaydi.
 */
class StandQueue
{
    public function refresh(Driver $driver): void
    {
        if ($driver->status !== DriverStatus::Free || ! $driver->last_location) {
            $this->leave($driver);

            return;
        }

        $standId = DB::scalar(
            'SELECT id FROM stands WHERE branch_id = ? AND is_active AND ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, radius_m)
             ORDER BY ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) LIMIT 1',
            [$driver->branch_id, $driver->last_location->getLongitude(), $driver->last_location->getLatitude(),
                $driver->last_location->getLongitude(), $driver->last_location->getLatitude()],
        );

        if ($standId === null) {
            $this->leave($driver);

            return;
        }

        // Boshqa navbat joyiga o'tsa — yangi joyda oxiriga
        DB::statement(
            'INSERT INTO stand_queue (driver_id, stand_id, joined_at) VALUES (?, ?, NOW())
             ON CONFLICT (driver_id) DO UPDATE SET stand_id = EXCLUDED.stand_id,
                joined_at = CASE WHEN stand_queue.stand_id = EXCLUDED.stand_id THEN stand_queue.joined_at ELSE NOW() END',
            [$driver->id, (int) $standId],
        );
    }

    public function leave(Driver $driver): void
    {
        DB::table('stand_queue')->where('driver_id', $driver->id)->delete();
    }

    /**
     * Haydovchi ilovasi uchun: qaysi navbat joyida, nechanchi o'rinda.
     *
     * @return array{stand: string, position: int, total: int}|null
     */
    public function position(Driver $driver): ?array
    {
        $row = DB::selectOne(
            'SELECT s.name, q.joined_at, q.stand_id FROM stand_queue q JOIN stands s ON s.id = q.stand_id WHERE q.driver_id = ?',
            [$driver->id],
        );
        if (! $row) {
            return null;
        }

        return [
            'stand' => (string) $row->name,
            'position' => (int) DB::table('stand_queue')->where('stand_id', $row->stand_id)->where('joined_at', '<=', $row->joined_at)->count(),
            'total' => (int) DB::table('stand_queue')->where('stand_id', $row->stand_id)->count(),
        ];
    }

    /**
     * Buyurtma navbat joyi hududidan chiqqan bo'lsa — shu navbatdagi haydovchilar, kelish tartibida.
     *
     * @return list<int>
     */
    public function queueFor(Order $order): array
    {
        $rows = DB::select(
            'SELECT q.driver_id FROM stand_queue q JOIN stands s ON s.id = q.stand_id
             WHERE s.branch_id = ? AND s.is_active
               AND ST_DWithin(s.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, s.radius_m)
             ORDER BY q.joined_at',
            [$order->branch_id, $order->pickup_location->getLongitude(), $order->pickup_location->getLatitude()],
        );

        return array_values(array_map(fn ($row): int => (int) $row->driver_id, $rows));
    }
}
