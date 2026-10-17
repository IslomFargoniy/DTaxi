<?php

use App\Enums\IntegrationProvider;
use App\Models\Branch;
use App\Models\Integration;
use App\Support\Settings;

test('settings fall back from branch to global to default', function () {
    $settings = app(Settings::class);
    $branch = Branch::factory()->create();

    expect($settings->get('drivers.commission_percent', $branch))->toBe(10);

    $settings->set('drivers.commission_percent', 12);
    expect($settings->get('drivers.commission_percent', $branch))->toBe(12);

    $settings->set('drivers.commission_percent', 15, $branch);
    expect($settings->get('drivers.commission_percent', $branch))->toBe(15)
        ->and($settings->get('drivers.commission_percent'))->toBe(12);
});

test('plan defaults are in place', function () {
    $settings = app(Settings::class);

    expect($settings->get('drivers.commission_percent'))->toBe(10)
        ->and($settings->get('drivers.min_balance'))->toBe(5000)
        ->and($settings->get('tariffs.rounding_step'))->toBe(500)
        ->and($settings->get('calls.recordings_retention_days'))->toBe(90);
});

test('unknown setting keys are rejected', function () {
    app(Settings::class)->get('no.such.key');
})->throws(InvalidArgumentException::class);

test('integration credentials are encrypted at rest and masked for display', function () {
    $integration = Integration::for(IntegrationProvider::Click);
    $integration->fill(['is_enabled' => true, 'credentials' => ['merchant_id' => '123', 'secret_key' => 'super-secret-9876']])->save();

    $raw = DB::table('integrations')->value('credentials');
    expect($raw)->not->toContain('super-secret');

    expect($integration->fresh()->maskedCredentials())->toMatchArray([
        'merchant_id' => '123',
        'secret_key' => '••••9876',
    ]);
    expect(Integration::credential(IntegrationProvider::Click, 'secret_key'))->toBe('super-secret-9876');
});

test('disabled integrations do not expose credentials', function () {
    Integration::for(IntegrationProvider::Payme)
        ->fill(['is_enabled' => false, 'credentials' => ['key' => 'k']])->save();

    expect(Integration::credential(IntegrationProvider::Payme, 'key'))->toBeNull();
});
