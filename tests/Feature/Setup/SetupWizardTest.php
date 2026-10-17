<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::forget('setup.complete'));

test('fresh install redirects everything to the setup wizard', function () {
    $this->get('/login')->assertRedirect(route('setup.show'));
    $this->get(route('setup.show'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/setup'));
});

test('setup creates the company, first branch and superadmin', function () {
    $this->post(route('setup.store'), [
        'company_name' => 'Ibrat taksi', 'locale' => 'ru',
        'name' => 'Boss', 'email' => 'boss@firma.uz', 'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
        'branch_name' => "Farg'ona",
    ])->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'boss@firma.uz')->sole();
    expect($user->hasRole(Role::Superadmin))->toBeTrue()
        ->and(Branch::query()->sole()->tariffClasses()->count())->toBe(4)
        ->and(app(Settings::class)->get('company.name'))->toBe('Ibrat taksi');
    $this->assertAuthenticatedAs($user);

    // Ikkinchi marta ochilmaydi
    auth()->logout();
    $this->get(route('setup.show'))->assertRedirect(route('login'));
    $this->post(route('setup.store'), [])->assertForbidden();
});
