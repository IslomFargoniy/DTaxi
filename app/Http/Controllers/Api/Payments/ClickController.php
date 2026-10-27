<?php

namespace App\Http\Controllers\Api\Payments;

use App\Enums\IntegrationProvider;
use App\Enums\TransactionSource;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentTopUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Click SHOP API (Prepare / Complete): haydovchi Click ilovasida pozivnoyini kiritib balansni to'ldiradi.
 * Kalitlar — firmaning o'z merchant shartnomasi, panelda (Integratsiyalar).
 */
class ClickController extends Controller
{
    private const OK = 0;

    private const SIGN_FAILED = -1;

    private const BAD_AMOUNT = -2;

    private const ALREADY_PAID = -4;

    private const USER_NOT_FOUND = -5;

    private const TRANSACTION_NOT_FOUND = -6;

    private const BAD_REQUEST = -8;

    private const CANCELLED = -9;

    public function __construct(private PaymentTopUp $topUp) {}

    public function prepare(Request $request): JsonResponse
    {
        if ($error = $this->verify($request, prepare: true)) {
            return $error;
        }

        $driver = $this->topUp->findDriver($request->input('merchant_trans_id'));
        if (! $driver) {
            return $this->reply($request, self::USER_NOT_FOUND, 'User does not exist');
        }
        $amount = $this->amount($request);
        if ($amount === null || ! $this->topUp->validAmount($amount)) {
            return $this->reply($request, self::BAD_AMOUNT, 'Incorrect parameter amount');
        }

        $payment = PaymentTransaction::query()->firstOrCreate(
            ['provider' => 'click', 'provider_transaction_id' => (string) $request->input('click_trans_id')],
            ['driver_id' => $driver->id, 'amount' => $amount, 'state' => 'created', 'raw_request' => $request->except('sign_string')],
        );
        if ($payment->state === 'performed') {
            return $this->reply($request, self::ALREADY_PAID, 'Already paid');
        }
        if ($payment->state === 'cancelled') {
            return $this->reply($request, self::CANCELLED, 'Transaction cancelled');
        }

        return $this->reply($request, self::OK, 'Success', ['merchant_prepare_id' => $payment->id]);
    }

    public function complete(Request $request): JsonResponse
    {
        if ($error = $this->verify($request, prepare: false)) {
            return $error;
        }

        $payment = PaymentTransaction::query()->where('provider', 'click')
            ->whereKey((int) $request->input('merchant_prepare_id'))
            ->where('provider_transaction_id', (string) $request->input('click_trans_id'))
            ->first();
        if (! $payment) {
            return $this->reply($request, self::TRANSACTION_NOT_FOUND, 'Transaction does not exist');
        }
        if ($payment->state === 'performed') {
            return $this->reply($request, self::ALREADY_PAID, 'Already paid', ['merchant_confirm_id' => $payment->id]);
        }
        if ($payment->state === 'cancelled') {
            return $this->reply($request, self::CANCELLED, 'Transaction cancelled');
        }
        if ($this->amount($request) !== $payment->amount) {
            return $this->reply($request, self::BAD_AMOUNT, 'Incorrect parameter amount');
        }

        // Click tomonida to'lov amalga oshmadi (error < 0) — bekor qilinadi
        if ((int) $request->input('error') < 0) {
            $this->topUp->cancel($payment, TransactionSource::Click);

            return $this->reply($request, self::CANCELLED, 'Transaction cancelled');
        }

        $this->topUp->perform($payment, TransactionSource::Click);
        // Shu orada (parallel so'rovda) bekor qilingan bo'lsa — pul tushmadi, "Success" deyilmaydi
        if ($payment->refresh()->state !== 'performed') {
            return $this->reply($request, self::CANCELLED, 'Transaction cancelled');
        }

        return $this->reply($request, self::OK, 'Success', ['merchant_confirm_id' => $payment->id]);
    }

    /**
     * Click summani so'mda yuboradi ("5000" yoki "5000.00"). Tiyinli summa yaxlitlanmaydi — rad etiladi,
     * aks holda balansga to'langandan boshqa summa yozilardi.
     */
    private function amount(Request $request): ?int
    {
        $raw = trim((string) $request->input('amount'));
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $raw, $m) !== 1 || (isset($m[2]) && trim($m[2], '0') !== '')) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Imzo: md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id [+ merchant_prepare_id] + amount + action + sign_time).
     */
    private function verify(Request $request, bool $prepare): ?JsonResponse
    {
        $secret = Integration::credential(IntegrationProvider::Click, 'secret_key');
        $serviceId = Integration::credential(IntegrationProvider::Click, 'service_id');

        foreach (['click_trans_id', 'service_id', 'merchant_trans_id', 'amount', 'action', 'sign_time', 'sign_string'] as $field) {
            if (! $request->filled($field)) {
                return $this->reply($request, self::BAD_REQUEST, 'Error in request from click');
            }
        }
        if (! $secret || (string) $request->input('service_id') !== (string) $serviceId) {
            return $this->reply($request, self::BAD_REQUEST, 'Error in request from click');
        }
        if ((int) $request->input('action') !== ($prepare ? 0 : 1)) {
            return $this->reply($request, -3, 'Action not found');
        }

        $sign = md5(
            $request->input('click_trans_id').$request->input('service_id').$secret.$request->input('merchant_trans_id')
            .($prepare ? '' : $request->input('merchant_prepare_id'))
            .$request->input('amount').$request->input('action').$request->input('sign_time')
        );

        return hash_equals($sign, (string) $request->input('sign_string'))
            ? null
            : $this->reply($request, self::SIGN_FAILED, 'SIGN CHECK FAILED!');
    }

    /**
     * @param  array<string, int>  $extra
     */
    private function reply(Request $request, int $error, string $note, array $extra = []): JsonResponse
    {
        return response()->json([
            'click_trans_id' => (int) $request->input('click_trans_id'),
            'merchant_trans_id' => (string) $request->input('merchant_trans_id'),
            ...$extra,
            'error' => $error,
            'error_note' => $note,
        ]);
    }
}
