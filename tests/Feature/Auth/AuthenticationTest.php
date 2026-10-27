<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'login' => $user->username,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login'), [
        'login' => $user->username,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can log in with email too, and inactive users cannot log in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['login' => strtoupper((string) $user->email), 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    auth()->logout();

    $user->forceFill(['is_active' => false])->save();
    $this->post(route('login.store'), ['login' => $user->username, 'password' => 'password']);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'login' => $user->username,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->username, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'login' => $user->username,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

test('drivers cannot sign in to the web panel', function () {
    $this->seed(RolesSeeder::class);
    $user = User::factory()->create(['email' => 'driver-+998901112233@drivers.local'])->assignRole(Role::Driver);

    $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});
