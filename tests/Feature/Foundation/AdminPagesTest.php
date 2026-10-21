<?php

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed(RolesSeeder::class);
    $this->superadmin = User::factory()->create()->assignRole(Role::Superadmin);
});

test('only superadmin can open admin pages', function (string $route) {
    $admin = User::factory()->create()->assignRole(Role::Admin);

    $this->actingAs($admin)->get(route($route))->assertForbidden();
    $this->actingAs($this->superadmin)->get(route($route))->assertOk();
})->with(['admin.integrations.index', 'admin.company-settings.edit']);

test('integration keys are saved and blank secrets keep the old value', function () {
    $this->actingAs($this->superadmin);

    $this->put(route('admin.integrations.update', 'click'), [
        'is_enabled' => true,
        'credentials' => ['merchant_id' => '42', 'service_id' => '7', 'merchant_user_id' => '1', 'secret_key' => 'first-secret'],
    ])->assertRedirect(route('admin.integrations.index'));

    $this->put(route('admin.integrations.update', 'click'), [
        'is_enabled' => true,
        'credentials' => ['merchant_id' => '43', 'service_id' => '7', 'merchant_user_id' => '1', 'secret_key' => ''],
    ])->assertSessionHasNoErrors();

    expect(Integration::credential(IntegrationProvider::Click, 'merchant_id'))->toBe('43')
        ->and(Integration::credential(IntegrationProvider::Click, 'secret_key'))->toBe('first-secret');
});

test('integration page never sends secrets to the browser', function () {
    Integration::for(IntegrationProvider::SpeechKit)
        ->fill(['is_enabled' => true, 'credentials' => ['api_key' => 'AQVN-very-secret-1234', 'folder_id' => 'b1g', 'voice' => 'zamira']])
        ->save();

    $this->actingAs($this->superadmin)->get(route('admin.integrations.index'))
        ->assertOk()
        ->assertDontSee('very-secret')
        ->assertSee('1234');
});

test('select fields only accept known options', function () {
    $this->actingAs($this->superadmin)->put(route('admin.integrations.update', 'speechkit'), [
        'is_enabled' => true,
        'credentials' => ['voice' => 'unknown-voice'],
    ])->assertSessionHasErrors('credentials.voice');
});

test('company settings are saved', function () {
    $this->actingAs($this->superadmin)->put(route('admin.company-settings.update'), [
        'company__name' => 'Ibrat taksi',
        'company__default_locale' => 'ru',
        'drivers__commission_percent' => '12',
        'drivers__min_balance' => '7000',
        'tariffs__rounding_step' => '1000',
        'calls__recordings_retention_days' => '30',
    ])->assertRedirect(route('admin.company-settings.edit'));

    $settings = app(Settings::class);
    expect($settings->get('company.name'))->toBe('Ibrat taksi')
        ->and($settings->get('drivers.commission_percent'))->toBe(12)
        ->and($settings->get('tariffs.rounding_step'))->toBe(1000);
});

test('chat quick replies are edited as an ordered list', function () {
    $this->actingAs($this->superadmin)->get(route('admin.company-settings.edit'))
        ->assertInertia(fn ($page) => $page->where('settings', fn ($settings) => collect($settings)->get('chat.quick_replies') === Settings::DEFAULTS['chat.quick_replies']));

    $this->putJson(route('admin.company-settings.update'), [
        'company__name' => 'Ibrat taksi',
        'company__default_locale' => 'uz',
        'drivers__commission_percent' => 10,
        'drivers__min_balance' => 5000,
        'tariffs__rounding_step' => 500,
        'calls__recordings_retention_days' => 90,
        'chat__quick_replies' => ['  Mijoz chiqmoqda ', 'Kuting'],
    ])->assertRedirect(route('admin.company-settings.edit'));

    expect(app(Settings::class)->get('chat.quick_replies'))->toBe(['Mijoz chiqmoqda', 'Kuting'])
        // Boshqa sozlama nuqta bilan bo'linib ketmagan
        ->and(app(Settings::class)->get('company.name'))->toBe('Ibrat taksi');

    // Operator panelida (chat ko'radiganlarga) shu ro'yxat keladi
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('chatQuickReplies', ['Mijoz chiqmoqda', 'Kuting']));
});

test('chat quick replies are validated', function (array $replies, string $error) {
    $base = [
        'company__name' => 'Ibrat taksi',
        'company__default_locale' => 'uz',
        'drivers__commission_percent' => 10,
        'drivers__min_balance' => 5000,
        'tariffs__rounding_step' => 500,
        'calls__recordings_retention_days' => 90,
    ];

    $this->actingAs($this->superadmin)
        ->putJson(route('admin.company-settings.update'), [...$base, 'chat__quick_replies' => $replies])
        ->assertJsonValidationErrors($error);

    expect(app(Settings::class)->get('chat.quick_replies'))->toBe(Settings::DEFAULTS['chat.quick_replies']);
})->with([
    'too many' => [array_map(fn (int $i) => "Javob {$i}", range(1, 21)), 'chat.quick_replies'],
    'too long' => [[str_repeat('a', 201)], 'chat.quick_replies.0'],
    'empty item' => [['Kuting', ''], 'chat.quick_replies.1'],
    'duplicate' => [['Kuting', 'Kuting'], 'chat.quick_replies.0'],
]);

test('an empty quick replies list hides the buttons', function () {
    $this->actingAs($this->superadmin)->putJson(route('admin.company-settings.update'), [
        'company__name' => 'Ibrat taksi',
        'company__default_locale' => 'uz',
        'drivers__commission_percent' => 10,
        'drivers__min_balance' => 5000,
        'tariffs__rounding_step' => 500,
        'calls__recordings_retention_days' => 90,
        'chat__quick_replies' => [],
    ])->assertRedirect();

    expect(app(Settings::class)->get('chat.quick_replies'))->toBe([]);
});

test('users can switch the interface language', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('locale.update'), ['locale' => 'ru'])->assertRedirect();

    expect($user->fresh()->locale)->toBe('ru');
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('locale', 'ru')->where('translations.Dashboard', 'Главная'));
});
