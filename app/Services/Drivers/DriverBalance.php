<?php

namespace App\Services\Drivers;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\CashShift;
use App\Models\Driver;
use App\Models\DriverTransaction;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Haydovchi balansi (reja §5c): har harakat qatorni qulflab bajariladi va tarixga yoziladi.
 */
class DriverBalance
{
    public function __construct(private Settings $settings) {}

    public function topUp(
        Driver $driver,
        int $amount,
        TransactionSource $source,
        ?User $by = null,
        ?CashShift $cashShift = null,
        ?string $comment = null,
    ): DriverTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('To\'ldirish summasi musbat bo\'lishi kerak.');
        }

        return $this->apply($driver, $amount, TransactionType::Topup, $source, by: $by, cashShift: $cashShift, comment: $comment);
    }

    /**
     * Yakunlangan buyurtmadan komissiya: yakuniy narx × foiz (standart 10% — qaror #34).
     */
    public function chargeCommission(Order $order): ?DriverTransaction
    {
        if (! $order->driver_id || ! $order->price_final) {
            return null;
        }

        $percent = (float) $this->settings->get('drivers.commission_percent', $order->branch_id);
        $commission = (int) round($order->price_final * $percent / 100);

        $order->forceFill(['commission' => $commission])->save();

        if ($commission === 0) {
            return null;
        }

        return $this->apply(
            Driver::query()->withoutGlobalScopes()->findOrFail($order->driver_id),
            -$commission,
            TransactionType::Commission,
            TransactionSource::System,
            order: $order,
        );
    }

    public function penalty(Driver $driver, int $amount, string $reason, ?Order $order = null): ?DriverTransaction
    {
        if ($amount <= 0) {
            return null;
        }

        return $this->apply($driver, -$amount, TransactionType::Penalty, TransactionSource::System, order: $order, comment: $reason);
    }

    /**
     * Admin qo'lda tuzatishi (bonus yoki jarima) — izoh majburiy.
     */
    public function adjust(Driver $driver, int $amount, User $by, string $comment): DriverTransaction
    {
        if ($amount === 0 || trim($comment) === '') {
            throw new InvalidArgumentException('Summa va izoh majburiy.');
        }

        return $this->apply(
            $driver,
            $amount,
            $amount > 0 ? TransactionType::Bonus : TransactionType::Adjustment,
            TransactionSource::System,
            by: $by,
            comment: $comment,
        );
    }

    public function canTakeOrders(Driver $driver): bool
    {
        return $driver->balance >= (int) $this->settings->get('drivers.min_balance', $driver->branch_id);
    }

    private function apply(
        Driver $driver,
        int $amount,
        TransactionType $type,
        TransactionSource $source,
        ?Order $order = null,
        ?User $by = null,
        ?CashShift $cashShift = null,
        ?string $comment = null,
    ): DriverTransaction {
        return DB::transaction(function () use ($driver, $amount, $type, $source, $order, $by, $cashShift, $comment) {
            $locked = Driver::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($driver->id);
            $locked->balance += $amount;
            $locked->save();

            $driver->balance = $locked->balance;

            if ($cashShift && $amount > 0) {
                $cashShift->increment('total_cash', $amount);
            }

            return DriverTransaction::query()->create([
                'driver_id' => $driver->id,
                'order_id' => $order?->id,
                'cash_shift_id' => $cashShift?->id,
                'type' => $type,
                'source' => $source,
                'amount' => $amount,
                'balance_after' => $locked->balance,
                'created_by' => $by?->id,
                'comment' => $comment,
                'created_at' => now(),
            ]);
        });
    }
}
