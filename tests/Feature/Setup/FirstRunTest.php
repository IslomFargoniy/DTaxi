<?php

use App\Console\Commands\InitApp;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Driver;
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

test('dtaxi:init prints credentials once and saves them to a private file', function () {
    $this->artisan('dtaxi:init', ['--company' => 'Ibrat taksi', '--username' => 'Boss', '--email' => 'boss@firma.uz'])
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

    $this->artisan('dtaxi:init')
        ->expectsOutputToContain('allaqachon mavjud')
        ->doesntExpectOutputToContain('Parol:')
        ->assertSuccessful();
});

test('dtaxi:admin-password sets a new random password', function () {
    $this->artisan('dtaxi:admin-password')->assertFailed();

    Artisan::call('dtaxi:init', ['--email' => 'boss@firma.uz']);
    $old = User::query()->sole()->password;

    $this->artisan('dtaxi:admin-password', ['login' => 'admin'])
        ->expectsOutputToContain('Parol:')
        ->assertSuccessful();
    expect(User::query()->sole()->password)->not->toBe($old);

    $this->artisan('dtaxi:admin-password', ['login' => 'boss@firma.uz'])->assertSuccessful();
    $this->artisan('dtaxi:admin-password', ['login' => 'nobody'])->assertFailed();
});

test('local environment uses the shared test password', function () {
    app()->detectEnvironment(fn () => 'local');
    $seeder = new FirstRunSeeder;
    $seeder->run();

    expect($seeder->credentials)->toBe(['login' => 'admin', 'password' => FirstRunSeeder::DEV_PASSWORD]);
});

test('login works without a superadmin and hints to run dtaxi:init; setup wizard is gone', function () {
    $this->get('/setup')->assertNotFound();
    $this->get(route('login'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/login')->where('needsInit', true));

    (new FirstRunSeeder)->run();
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('needsInit', false));
});

test('dtaxi:init --demo creates one account per role with the test password, idempotently', function () {
    $this->artisan('dtaxi:init', ['--demo' => true])
        ->expectsOutputToContain('Parol:  '.FirstRunSeeder::DEV_PASSWORD)
        ->expectsOutputToContain('operator: operator')
        ->assertSuccessful();

    $branch = Branch::query()->sole();
    foreach (['admin' => Role::Superadmin, ...FirstRunSeeder::DEMO_STAFF] as $username => $role) {
        $user = User::query()->where('username', $username)->sole();
        expect($user->hasRole($role))->toBeTrue()
            ->and(Hash::check(FirstRunSeeder::DEV_PASSWORD, $user->password))->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->branch_id)->toBe($role === Role::Superadmin ? null : $branch->id);
    }
    expect(User::query()->where('username', 'operator')->sole()->sip_extension)->not->toBeNull();

    $driver = Driver::query()->with('user', 'vehicle.tariffClasses')->sole();
    expect($driver->user->phone)->toBe(FirstRunSeeder::DEMO_DRIVER_PHONE)
        ->and($driver->user->hasRole(Role::Driver))->toBeTrue()
        ->and(Hash::check(FirstRunSeeder::DEV_PASSWORD, $driver->user->password))->toBeTrue()
        ->and($driver->is_approved)->toBeTrue()
        ->and($driver->vehicle?->is_approved)->toBeTrue()
        ->and($driver->vehicle?->tariffClasses)->not->toBeEmpty();

    // Qayta chaqirish: o'zgartirilgan parol saqlanadi, nusxa yaratilmaydi
    User::query()->where('username', 'operator')->sole()->update(['password' => 'yangi-parol-123']);
    $this->artisan('dtaxi:init', ['--demo' => true])->assertSuccessful();
    expect(User::query()->count())->toBe(5)
        ->and(Driver::query()->count())->toBe(1)
        ->and(Hash::check('yangi-parol-123', User::query()->where('username', 'operator')->sole()->password))->toBeTrue();
});

test('demo mode does not change an existing superadmin password', function () {
    Artisan::call('dtaxi:init');
    $hash = User::query()->sole()->password;

    $this->artisan('dtaxi:init', ['--demo' => true])->assertSuccessful();
    expect(User::query()->where('username', 'admin')->sole()->password)->toBe($hash);
});
