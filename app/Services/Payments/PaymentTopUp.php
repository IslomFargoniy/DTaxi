<?php

namespace App\Services\Payments;

use App\Enums\TransactionSource;
use App\Jobs\SendPushNotification;
use App\Models\Driver;
use App\Models\PaymentTransaction;
use App\Services\Drivers\DriverBalance;
use Illuminate\Support\Facades\DB;

/**
 * Click/Payme to'lovi → haydovchi balansi (qaror #23, reja §5c). Har to'lov bir marta hisoblanadi.
 */
class PaymentTopUp
{
    /** So'mda: juda kichik to'lovlar rad etiladi */
    public const MIN_AMOUNT = 1000;

    public const MAX_AMOUNT = 10_000_000;

    public function __construct(private DriverBalance $balance) {}

    /**
     * Haydovchi to'lovda kiritadigan ID — pozivnoy (butun nusxada yagona).
     */
    public function findDriver(mixed $id): ?Driver
    {
        $id = is_scalar($id) ? trim((string) $id) : '';

        return $id === '' ? null : Driver::query()->withoutGlobalScopes()->where('callsign', $id)->first();
    }

    public function validAmount(int $amount): bool
    {
        return $amount >= self::MIN_AMOUNT && $amount <= self::MAX_AMOUNT;
    }

    /**
     * To'lovni bajarish: balansga qo'shish (qulf ostida, takrorlanmaydi) va haydovchiga push.
     */
    public function perform(PaymentTransaction $payment, TransactionSource $source): void
    {
        $performed = DB::transaction(function () use ($payment, $source) {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->state !== 'created') {
                return false;
            }

            $driver = Driver::query()->withoutGlobalScopes()->findOrFail($locked->driver_id);
            $this->balance->topUp($driver, $locked->amount, $source, comment: ucfirst($source->value)." #{$locked->provider_transaction_id}");
            $locked->forceFill(['state' => 'performed', 'performed_at' => now()])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);

            return $driver;
        });

        if ($performed instanceof Driver) {
            SendPushNotification::dispatch($performed->user_id, [
                'type' => 'balance.topup',
                'amount' => $payment->amount,
                'balance' => $performed->balance,
            ]);
        }
    }

    /**
     * Bekor qilish. Bajarilgan to'lov bekor qilinsa (Payme qaytarish) — summa balansdan qaytariladi.
     *
     * @throws PaymentNotCancellable haydovchi pulni allaqachon ishlatgan (balans summadan kam)
     */
    public function cancel(PaymentTransaction $payment, TransactionSource $source, ?int $reason = null): void
    {
        DB::transaction(function () use ($payment, $source, $reason) {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);
            if (in_array($locked->state, ['cancelled', 'refunded'], true)) {
                return;
            }

            $refund = $locked->state === 'performed';
            if ($refund) {
                // Qulf ostida tekshiriladi: parallel komissiya yoki jarima balansni shu orada kamaytira olmaydi
                $driver = Driver::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->driver_id);
                if ($driver->balance < $locked->amount) {
                    throw new PaymentNotCancellable;
                }
                $this->balance->refund($driver, $locked->amount, $source, ucfirst($source->value)." #{$locked->provider_transaction_id} bekor qilindi");
            }

            $locked->forceFill([
                'state' => $refund ? 'refunded' : 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
