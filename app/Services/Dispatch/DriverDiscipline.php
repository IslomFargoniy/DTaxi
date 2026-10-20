<?php

namespace App\Services\Dispatch;

use App\Enums\TransactionType;
use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\User;
use App\Services\Drivers\DriverBalance;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Jarima, reyting va vaqtincha bloklash (qaror #29). Efirda taklifni olmaslik — jarimasiz.
 */
class DriverDiscipline
{
    public function __construct(
        private Settings $settings,
        private DriverBalance $balance,
    ) {}

    /**
     * Haydovchi qabul qilgan buyurtmani bekor qildi.
     */
    public function cancelled(Driver $driver, Order $order, string $reason, bool $excused = false): void
    {
        $penalty = $excused ? 0 : (int) $this->settings->get('drivers.cancel_penalty', $driver->branch_id);

        OrderCancellation::query()->create([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'reason' => $reason,
            'penalty_amount' => $penalty,
            'created_at' => now(),
        ]);

        if ($excused) {
            return;
        }

        $this->balance->penalty($driver, $penalty, "Bekor qilish: {$reason}", $order);
        $this->lowerRating($driver);
        $this->blockIfTooManyCancels($driver);
    }

    /**
     * Operator tayinlagan taklifni rad etdi yoki javob bermadi.
     */
    public function directOfferMissed(Driver $driver, Order $order, string $kind): void
    {
        $this->cancelled($driver, $order, $kind === 'rejected' ? 'Tayinlangan buyurtmani rad etdi' : 'Tayinlangan buyurtmaga javob bermadi');
    }

    /**
     * Admin jarimani uzrli deb topdi: pul va reyting qaytadi, bekor qilish bloklash hisobiga kirmaydi.
     */
    public function excuse(DriverTransaction $penalty, User $by): void
    {
        if ($penalty->type !== TransactionType::Penalty || $penalty->excused_at !== null) {
            throw new InvalidArgumentException(__('This penalty cannot be excused.'));
        }

        DB::transaction(function () use ($penalty, $by) {
            $driver = Driver::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($penalty->driver_id);
            $penalty->forceFill(['excused_at' => now(), 'excused_by' => $by->id])->save();

            $this->balance->adjust($driver, abs($penalty->amount), $by, "Jarima qaytarildi (#{$penalty->id})");

            $rating = (int) $this->settings->get('drivers.rating_cancel_penalty', $driver->branch_id);
            $driver->forceFill(['rating' => min(100, $driver->rating + $rating)])->save();

            if ($penalty->order_id) {
                OrderCancellation::query()->where('driver_id', $driver->id)->where('order_id', $penalty->order_id)
                    ->update(['penalty_amount' => 0]);
            }
        });
    }

    public function completed(Driver $driver): void
    {
        $bonus = (int) $this->settings->get('drivers.rating_complete_bonus', $driver->branch_id);
        $driver->forceFill(['rating' => min(100, $driver->rating + $bonus)])->save();
    }

    private function lowerRating(Driver $driver): void
    {
        $penalty = (int) $this->settings->get('drivers.rating_cancel_penalty', $driver->branch_id);
        $driver->forceFill(['rating' => max(0, $driver->rating - $penalty)])->save();
    }

    private function blockIfTooManyCancels(Driver $driver): void
    {
        $limit = (int) $this->settings->get('drivers.max_cancels_per_shift', $driver->branch_id);
        // "Smena" — oxirgi 12 soat
        $count = OrderCancellation::query()
            ->where('driver_id', $driver->id)
            ->where('penalty_amount', '>', 0)
            ->where('created_at', '>=', now()->subHours(12))
            ->count();

        if ($limit > 0 && $count >= $limit) {
            $minutes = (int) $this->settings->get('drivers.block_minutes', $driver->branch_id);
            $driver->forceFill(['blocked_until' => now()->addMinutes($minutes)])->save();
        }
    }
}
