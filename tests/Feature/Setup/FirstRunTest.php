<?php

use App\Console\Commands\InitApp;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\FirstRunSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

afterEach(fn () => File::delete(InitApp::credentialsFile()));

test('first run creates one branch and a superadmin with a random password, only once', function () {
    config(['app.url' => 'https://taxi.firma.uz']);

    $seeder = new FirstRunSeeder;
    $seeder->company = 'Ibrat taksi';
    $seeder->run();

    $user = User::query()->where('username', 'admin')->sole();
    expect($seeder->credentials)->not->toBeNull()
        ->and(strlen($seeder->credentials['password']))->toBe(16)
        ->and(Hash::check($seeder->credentials['password'], $user->password))->toBeTrue()
        ->and($user->hasRole(Role::Superadmin))->toBeTrue()
        ->and($seeder->credentials['login'])->toBe('admin')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Branch::query()->sole()->only(['name', 'service_name']))->toBe(['name' => 'Asosiy filial', 'service_name' => 'Ibrat taksi'])
        ->and(app(Settings::class)->get('company.name'))->toBe('Ibrat taksi');

    // Qayta ishga tushirish mavjud superadmin va parolga tegmaydi
    $hash = $user->password;
    $again = new FirstRunSeeder;
    $again->company = 'Boshqa';
    $again->run();
    expect($again->credentials)->toBeNull()
        ->and($user->fresh()->password)->toBe($hash)
        ->and(Branch::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

test('ibtaxi:init prints credentials once and saves them to a private file', function () {
    $this->artisan('ibtaxi:init', ['--company' => 'Ibrat taksi', '--username' => 'Boss', '--email' => 'boss@firma.uz'])
        ->expectsOutputToContain('Login:  boss')
        ->expectsOutputToContain('Parol:')
        ->assertSuccessful();

    $file = InitApp::credentialsFile();
    expect(File::exists($file))->toBeTrue()
        ->and(File::get($file))->toContain('Login:  boss')
        ->and(fileperms($file) & 0777)->toBe(0600);

    $password = str(File::get($file))->match('/Parol:\s+(\S+)/')->toString();
    $this->post(route('login.store'), ['login' => 'boss', 'password' => $password])->assertRedirect(route('dashboard'));
    auth()->logout();

    $this->artisan('ibtaxi:init')
        ->expectsOutputToContain('allaqachon mavjud')
        ->doesntExpectOutputToContain('Parol:')
        ->assertSuccessful();
});

test('ibtaxi:admin-password sets a new random password', function () {
    $this->artisan('ibtaxi:admin-password')->assertFailed();

    Artisan::call('ibtaxi:init', ['--email' => 'boss@firma.uz']);
    $old = User::query()->sole()->password;

    $this->artisan('ibtaxi:admin-password', ['login' => 'admin'])
        ->expectsOutputToContain('Parol:')
        ->assertSuccessful();
    expect(User::query()->sole()->password)->not->toBe($old);

    $this->artisan('ibtaxi:admin-password', ['login' => 'boss@firma.uz'])->assertSuccessful();
    $this->artisan('ibtaxi:admin-password', ['login' => 'nobody'])->assertFailed();
});

test('local environment uses the shared test password', function () {
    app()->detectEnvironment(fn () => 'local');
    $seeder = new FirstRunSeeder;
    $seeder->run();

    expect($seeder->credentials)->toBe(['login' => 'admin', 'password' => FirstRunSeeder::DEV_PASSWORD]);
});

test('login works without a superadmin and hints to run ibtaxi:init; setup wizard is gone', function () {
    $this->get('/setup')->assertNotFound();
    $this->get(route('login'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/login')->where('needsInit', true));

    (new FirstRunSeeder)->run();
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('needsInit', false));
});
