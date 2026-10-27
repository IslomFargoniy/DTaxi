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
    /**
     * @param  int|null  $base  narx o'rniga hisob asosi (masofa kam ko'rsatilganda — GPS treki bo'yicha narx)
     */
    public function chargeCommission(Order $order, ?int $base = null): ?DriverTransaction
    {
        $base ??= $order->price_final;
        if (! $order->driver_id || ! $base) {
            return null;
        }

        // Buyurtma paytidagi foiz (snapshot); eski buyurtmalarda — joriy sozlama
        $snapshot = $order->tariff_snapshot;
        $percent = is_numeric($snapshot['commission_percent'] ?? null)
            ? (float) $snapshot['commission_percent']
            : (float) $this->settings->get('drivers.commission_percent', $order->branch_id);
        $commission = (int) round($base * $percent / 100);

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

    /**
     * Korporativ safar: haydovchi mijozdan pul olmagan — narx balansiga qo'shiladi.
     */
    public function corporatePayout(Order $order): ?DriverTransaction
    {
        if (! $order->driver_id || ! $order->price_final) {
            return null;
        }

        return $this->apply(
            Driver::query()->withoutGlobalScopes()->findOrFail($order->driver_id),
            $order->price_final,
            TransactionType::Corporate,
            TransactionSource::System,
            order: $order,
        );
    }

    /**
     * To'lov tizimi orqali kelgan pul qaytarildi. Chaqiruvchi haydovchi qatorini qulflab balans yetishini tekshiradi.
     */
    public function refund(Driver $driver, int $amount, TransactionSource $source, string $comment): DriverTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Qaytarish summasi musbat bo\'lishi kerak.');
        }

        return $this->apply($driver, -$amount, TransactionType::Refund, $source, comment: $comment);
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

    /**
     * Tungi tekshiruv: balans oxirgi harakatdagi "balance_after" ga teng va harakatlar zanjiri uzilmagan
     * (birinchi harakatdan oldingi balans + summalar = oxirgi balans). Farq — balans tarixsiz o'zgargan.
     *
     * @return list<array{driver_id: int, callsign: string, balance: int, expected: int}>
     */
    public function mismatches(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT d.id, d.callsign, d.balance,
                   CASE WHEN d.balance <> x.last_after THEN x.last_after ELSE x.first_before + x.total END AS expected
            FROM drivers d
            JOIN (
                SELECT driver_id,
                       (array_agg(balance_after ORDER BY id DESC))[1] AS last_after,
                       (array_agg(balance_after - amount ORDER BY id ASC))[1] AS first_before,
                       SUM(amount) AS total
                FROM driver_transactions
                GROUP BY driver_id
            ) x ON x.driver_id = d.id
            WHERE d.balance <> x.last_after OR x.first_before + x.total <> x.last_after
            ORDER BY d.id
            SQL);

        return array_values(array_map(fn (\stdClass $row) => [
            'driver_id' => (int) $row->id,
            'callsign' => (string) $row->callsign,
            'balance' => (int) $row->balance,
            'expected' => (int) $row->expected,
        ], $rows));
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

            // Chaqiruvchi modeli yangi balansni ko'radi, lekin "o'zgargan" deb belgilanmaydi: keyingi save()
            // (holat va h.k.) qulfdan tashqarida parallel to'ldirishni eski balans bilan yozib yubormasin
            $driver->balance = $locked->balance;
            $driver->syncOriginalAttribute('balance');

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
