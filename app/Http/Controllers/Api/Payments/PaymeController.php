<?php

namespace App\Http\Controllers\Api\Payments;

use App\Enums\IntegrationProvider;
use App\Enums\TransactionSource;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Integration;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentTopUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payme Merchant API (JSON-RPC 2.0): haydovchi Payme ilovasida pozivnoyini (`account.driver_id`) kiritib
 * balansni to'ldiradi. Summalar tiyinda. Kalit — firmaning o'z kassasi, panelda (Integratsiyalar).
 */
class PaymeController extends Controller
{
    /** Payme: yaratilgan, lekin bajarilmagan tranzaksiya 12 soatdan keyin bekor */
    private const TIMEOUT_MS = 43_200_000;

    /** Holat kodlari Payme hujjatiga ko'ra */
    private const STATES = ['created' => 1, 'performed' => 2, 'cancelled' => -1, 'refunded' => -2];

    public function __construct(private PaymentTopUp $topUp) {}

    public function __invoke(Request $request): JsonResponse
    {
        $id = $request->input('id');

        if (! $this->authorized($request)) {
            return $this->error($id, -32504, 'Insufficient privilege to perform this method');
        }

        $method = (string) $request->input('method');
        $params = (array) $request->input('params', []);

        try {
            $result = match ($method) {
                'CheckPerformTransaction' => $this->checkPerform($params),
                'CreateTransaction' => $this->create($params),
                'PerformTransaction' => $this->perform($params),
                'CancelTransaction' => $this->cancel($params),
                'CheckTransaction' => $this->check($params),
                'GetStatement' => $this->statement($params),
                default => throw new PaymeError(-32601, 'Method not found'),
            };
        } catch (PaymeError $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $e->data);
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function checkPerform(array $params): array
    {
        $this->driverAndAmount($params);

        return ['allow' => true];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function create(array $params): array
    {
        $payment = $this->find($params, required: false);

        if ($payment) {
            if ($payment->state !== 'created') {
                throw new PaymeError(-31008, 'Unable to complete operation');
            }
            if ($this->expired($payment)) {
                $this->topUp->cancel($payment, TransactionSource::Payme, 4);
                throw new PaymeError(-31008, 'Transaction timed out');
            }

            return $this->createResult($payment);
        }

        [$driver, $amount] = $this->driverAndAmount($params);
        $payment = PaymentTransaction::query()->create([
            'provider' => 'payme',
            'provider_transaction_id' => (string) $params['id'],
            'driver_id' => $driver->id,
            'amount' => $amount,
            'state' => 'created',
            'provider_time' => (int) ($params['time'] ?? 0),
            'raw_request' => $params,
        ]);

        return $this->createResult($payment);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function perform(array $params): array
    {
        $payment = $this->find($params);

        if ($payment->state === 'created') {
            if ($this->expired($payment)) {
                $this->topUp->cancel($payment, TransactionSource::Payme, 4);
                throw new PaymeError(-31008, 'Transaction timed out');
            }
            $this->topUp->perform($payment, TransactionSource::Payme);
            $payment->refresh();
        }
        if ($payment->state !== 'performed') {
            throw new PaymeError(-31008, 'Unable to complete operation');
        }

        return [
            'transaction' => (string) $payment->id,
            'perform_time' => $this->ms($payment->performed_at),
            'state' => self::STATES['performed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function cancel(array $params): array
    {
        $payment = $this->find($params);
        $this->topUp->cancel($payment, TransactionSource::Payme, isset($params['reason']) ? (int) $params['reason'] : null);
        $payment->refresh();

        return [
            'transaction' => (string) $payment->id,
            'cancel_time' => $this->ms($payment->cancelled_at),
            'state' => self::STATES[$payment->state],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function check(array $params): array
    {
        $payment = $this->find($params);

        return [
            'create_time' => $this->ms($payment->created_at),
            'perform_time' => $this->ms($payment->performed_at),
            'cancel_time' => $this->ms($payment->cancelled_at),
            'transaction' => (string) $payment->id,
            'state' => self::STATES[$payment->state],
            'reason' => $payment->cancel_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function statement(array $params): array
    {
        $payments = PaymentTransaction::query()->where('provider', 'payme')
            ->whereBetween('provider_time', [(int) ($params['from'] ?? 0), (int) ($params['to'] ?? 0)])
            ->orderBy('provider_time')
            ->get();

        return ['transactions' => $payments->map(fn (PaymentTransaction $p) => [
            'id' => $p->provider_transaction_id,
            'time' => $p->provider_time,
            'amount' => $p->amount * 100,
            'account' => ['driver_id' => data_get($p->raw_request, 'account.driver_id')],
            'create_time' => $this->ms($p->created_at),
            'perform_time' => $this->ms($p->performed_at),
            'cancel_time' => $this->ms($p->cancelled_at),
            'transaction' => (string) $p->id,
            'state' => self::STATES[$p->state],
            'reason' => $p->cancel_reason,
        ])->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{Driver, int} haydovchi va summa (so'm)
     */
    private function driverAndAmount(array $params): array
    {
        $driver = $this->topUp->findDriver(data_get($params, 'account.driver_id'));
        if (! $driver) {
            throw new PaymeError(-31050, 'Driver not found', 'driver_id');
        }

        $tiyin = (int) ($params['amount'] ?? 0);
        if ($tiyin % 100 !== 0 || ! $this->topUp->validAmount(intdiv($tiyin, 100))) {
            throw new PaymeError(-31001, 'Incorrect amount');
        }

        return [$driver, intdiv($tiyin, 100)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return ($required is true ? PaymentTransaction : PaymentTransaction|null)
     */
    private function find(array $params, bool $required = true): ?PaymentTransaction
    {
        $payment = PaymentTransaction::query()->where('provider', 'payme')
            ->where('provider_transaction_id', (string) ($params['id'] ?? ''))->first();

        if (! $payment && $required) {
            throw new PaymeError(-31003, 'Transaction not found');
        }

        return $payment;
    }

    /**
     * @return array<string, mixed>
     */
    private function createResult(PaymentTransaction $payment): array
    {
        return [
            'create_time' => $this->ms($payment->created_at),
            'transaction' => (string) $payment->id,
            'state' => self::STATES[$payment->state],
        ];
    }

    private function expired(PaymentTransaction $payment): bool
    {
        return $this->ms($payment->created_at) < (int) (microtime(true) * 1000) - self::TIMEOUT_MS;
    }

    private function ms(?\DateTimeInterface $time): int
    {
        return $time ? (int) $time->format('Uv') : 0;
    }

    /**
     * Basic auth: login "Paycom", parol — kassa kaliti. Test kaliti faqat integratsiya "sandbox" rejimida bo'lsa.
     */
    private function authorized(Request $request): bool
    {
        $sandbox = Integration::credential(IntegrationProvider::Payme, 'mode') === 'sandbox';
        $keys = array_filter([
            Integration::credential(IntegrationProvider::Payme, 'key'),
            $sandbox ? Integration::credential(IntegrationProvider::Payme, 'test_key') : null,
        ]);
        $header = (string) $request->header('Authorization');
        if ($keys === [] || ! str_starts_with($header, 'Basic ')) {
            return false;
        }

        [$login, $password] = array_pad(explode(':', (string) base64_decode(substr($header, 6)), 2), 2, '');

        return $login === 'Paycom' && collect($keys)->contains(fn (string $key) => hash_equals($key, $password));
    }

    private function error(mixed $id, int $code, string $message, ?string $data = null): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => array_filter([
            'code' => $code,
            'message' => ['uz' => $message, 'ru' => $message, 'en' => $message],
            'data' => $data,
        ], fn ($v) => $v !== null)]);
    }
}
