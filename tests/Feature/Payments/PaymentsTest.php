<?php

use App\Enums\IntegrationProvider;
use App\Jobs\SendPushNotification;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Integration;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->driver = Driver::factory()->create(['branch_id' => Branch::factory()->create()->id, 'callsign' => '101', 'balance' => 0]);

    Integration::for(IntegrationProvider::Click)->fill(['is_enabled' => true, 'credentials' => ['service_id' => '555', 'secret_key' => 'click-secret']])->save();
    Integration::for(IntegrationProvider::Payme)->fill(['is_enabled' => true, 'credentials' => ['merchant_id' => 'm1', 'key' => 'payme-key']])->save();
});

function clickRequest(int $action, array $overrides = []): array
{
    $data = [
        'click_trans_id' => 9001, 'service_id' => 555, 'click_paydoc_id' => 1, 'merchant_trans_id' => '101',
        'amount' => '50000.00', 'action' => $action, 'error' => 0, 'error_note' => 'Success', 'sign_time' => '2026-10-17 10:00:00',
        ...$overrides,
    ];
    $data['sign_string'] = md5($data['click_trans_id'].$data['service_id'].'click-secret'.$data['merchant_trans_id']
        .($action === 1 ? $data['merchant_prepare_id'] : '').$data['amount'].$data['action'].$data['sign_time']);

    return $data;
}

test('click prepare and complete top up the driver balance once', function () {
    $prepare = $this->post('/api/payments/click/prepare', clickRequest(0))->assertOk()->assertJsonPath('error', 0);
    $prepareId = $prepare->json('merchant_prepare_id');

    $this->post('/api/payments/click/complete', clickRequest(1, ['merchant_prepare_id' => $prepareId]))
        ->assertJsonPath('error', 0)->assertJsonPath('merchant_confirm_id', $prepareId);
    // Takroriy callback balansni ikki marta oshirmaydi
    $this->post('/api/payments/click/complete', clickRequest(1, ['merchant_prepare_id' => $prepareId]))->assertJsonPath('error', -4);

    expect($this->driver->refresh()->balance)->toBe(50000);
    Queue::assertPushed(SendPushNotification::class, 1);
});

test('click rejects bad signature, unknown driver and wrong amount', function () {
    $bad = clickRequest(0);
    $bad['sign_string'] = 'x';
    $this->post('/api/payments/click/prepare', $bad)->assertJsonPath('error', -1);
    $this->post('/api/payments/click/prepare', clickRequest(0, ['merchant_trans_id' => '999']))->assertJsonPath('error', -5);
    $this->post('/api/payments/click/prepare', clickRequest(0, ['amount' => '10']))->assertJsonPath('error', -2);

    expect(PaymentTransaction::query()->count())->toBe(0);
});

test('click failed payment is cancelled without touching the balance', function () {
    $prepareId = $this->post('/api/payments/click/prepare', clickRequest(0))->json('merchant_prepare_id');

    $this->post('/api/payments/click/complete', clickRequest(1, ['merchant_prepare_id' => $prepareId, 'error' => -5017]))
        ->assertJsonPath('error', -9);

    expect($this->driver->refresh()->balance)->toBe(0);
});

function payme(string $method, array $params, string $key = 'payme-key')
{
    return test()->withHeader('Authorization', 'Basic '.base64_encode("Paycom:{$key}"))
        ->postJson('/api/payments/payme', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

test('payme full cycle: check, create, perform, check, cancel with refund', function () {
    $account = ['driver_id' => '101'];

    payme('CheckPerformTransaction', ['amount' => 5_000_000, 'account' => $account])->assertJsonPath('result.allow', true);
    payme('CreateTransaction', ['id' => 'pm-1', 'time' => 1792212000000, 'amount' => 5_000_000, 'account' => $account])
        ->assertJsonPath('result.state', 1);
    // Idempotent
    payme('CreateTransaction', ['id' => 'pm-1', 'time' => 1792212000000, 'amount' => 5_000_000, 'account' => $account])
        ->assertJsonPath('result.state', 1);

    payme('PerformTransaction', ['id' => 'pm-1'])->assertJsonPath('result.state', 2);
    payme('PerformTransaction', ['id' => 'pm-1'])->assertJsonPath('result.state', 2);
    expect($this->driver->refresh()->balance)->toBe(50000);

    payme('CheckTransaction', ['id' => 'pm-1'])->assertJsonPath('result.state', 2);
    payme('GetStatement', ['from' => 1792211000000, 'to' => 1792213000000])->assertJsonCount(1, 'result.transactions');

    payme('CancelTransaction', ['id' => 'pm-1', 'reason' => 5])->assertJsonPath('result.state', -2);
    expect($this->driver->refresh()->balance)->toBe(0);
});

test('payme errors: auth, unknown driver, bad amount, missing transaction', function () {
    payme('CheckPerformTransaction', ['amount' => 5_000_000, 'account' => ['driver_id' => '101']], 'wrong')->assertJsonPath('error.code', -32504);
    payme('CheckPerformTransaction', ['amount' => 5_000_000, 'account' => ['driver_id' => '999']])->assertJsonPath('error.code', -31050);
    payme('CheckPerformTransaction', ['amount' => 50, 'account' => ['driver_id' => '101']])->assertJsonPath('error.code', -31001);
    payme('PerformTransaction', ['id' => 'nope'])->assertJsonPath('error.code', -31003);
    payme('Unknown', [])->assertJsonPath('error.code', -32601);
});

test('payme cancels a created transaction without a balance change', function () {
    payme('CreateTransaction', ['id' => 'pm-2', 'time' => 1792212000000, 'amount' => 2_000_000, 'account' => ['driver_id' => '101']]);
    payme('CancelTransaction', ['id' => 'pm-2', 'reason' => 3])->assertJsonPath('result.state', -1);
    payme('PerformTransaction', ['id' => 'pm-2'])->assertJsonPath('error.code', -31008);

    expect($this->driver->refresh()->balance)->toBe(0);
});
