<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Pjsip\PsEndpoint;
use App\Models\TariffClass;
use App\Models\User;
use App\Models\Vehicle;
use App\Rules\Phone;
use App\Support\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    Queue::fake();
    $this->seed(RolesSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id])->assignRole(Role::Admin);
});

function formatDriverPayload(Branch $branch, array $overrides = [], array $vehicle = []): array
{
    return [
        'branch_id' => $branch->id,
        'name' => 'Aziz Karimov',
        'phone' => '+998 90 555 44 33',
        'password' => 'secret12',
        'callsign' => '777',
        'vehicle' => [
            'brand' => 'Chevrolet', 'model' => 'Cobalt', 'color' => 'oq', 'plate_number' => '01 A 123 BC',
            'tariff_class_ids' => TariffClass::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->pluck('id')->all(),
            ...$vehicle,
        ],
        ...$overrides,
    ];
}

test('phone numbers in any pasted form normalize to E.164', function (string $input) {
    expect(Client::normalizePhone($input))->toBe('+998901234567')
        ->and(Phone::passes($input))->toBeTrue();
})->with([
    '+998901234567', '998901234567', '901234567', '90 123 45 67', '+998 (90) 123-45-67',
    '8 90 123 45 67', '00998901234567', ' 90-123-45-67 ',
]);

test('phone rule rejects incomplete, invalid and non-numeric values', function (string $input) {
    expect(Phone::passes($input))->toBeFalse()
        ->and(Validator::make(['phone' => $input], ['phone' => [new Phone]])->fails())->toBeTrue();
})->with(['90 123 45', '+7 912 345', '90123456789', 'abc901234567', '+998 90 123 45 678', '+999 123 456 789']);

test('foreign numbers with a country code are accepted and formatted', function (string $input, string $e164, string $display) {
    expect(Phone::passes($input))->toBeTrue()
        ->and(Client::normalizePhone($input))->toBe($e164)
        ->and(Client::formatPhone($e164))->toBe($display);
})->with([
    ['+7 912 345 67 89', '+79123456789', '+7 912 345 67 89'],
    ['+7 701 234 5678', '+77012345678', '+7 701 234 5678'],
    ['00 996 555 123 456', '+996555123456', '+996 555 123 456'],
    ['+90 532 123 45 67', '+905321234567', '+90 532 123 45 67'],
]);

test('numbers without a country code belong to the firm country', function () {
    app(Settings::class)->set('company.phone_country', 'KZ');

    expect(Client::normalizePhone('701 234 5678'))->toBe('+77012345678')
        ->and(Phone::passes('701 234 5678'))->toBeTrue()
        ->and(Phone::passes('90 123 45 67'))->toBeFalse();
});

test('phone helpers format for display and search', function () {
    expect(Client::formatPhone('+998901234567'))->toBe('+998 90 123 45 67')
        ->and(Client::formatPhone('101'))->toBe('101')
        ->and(Client::phoneSearchDigits('90 123'))->toBe('90123')
        ->and(Client::phoneSearchDigits('8 90 123 45 67'))->toBe('998901234567')
        ->and(Client::phoneSearchDigits('Ali'))->toBe('');
});

test('plates normalize, validate and format in both Uzbek layouts', function () {
    expect(Vehicle::normalizePlate(' 01 a 123 bc '))->toBe('01A123BC')
        ->and(Vehicle::plateIsValid('01 a 123 bc'))->toBeTrue()
        ->and(Vehicle::plateIsValid('01 123 abc'))->toBeTrue()
        ->and(Vehicle::plateIsValid('01 AB 123 C'))->toBeFalse()
        ->and(Vehicle::plateIsValid('ABC'))->toBeFalse()
        ->and(Vehicle::formatPlate('01A123BC'))->toBe('01 A 123 BC')
        ->and(Vehicle::formatPlate('01123ABC'))->toBe('01 123 ABC')
        ->and(Vehicle::formatPlate('T123456'))->toBe('T123456');
});

test('driver form rejects bad phone, letters in callsign and unknown plate format', function () {
    $this->actingAs($this->admin)->post(route('admin.drivers.store'), formatDriverPayload($this->branch, [
        'phone' => '90 123',
        'callsign' => '7a7',
    ], ['plate_number' => 'ABC 1']))->assertSessionHasErrors(['phone', 'callsign', 'vehicle.plate_number']);

    $this->post(route('admin.drivers.store'), formatDriverPayload($this->branch, vehicle: ['plate_number' => '01 123 abc']))
        ->assertSessionHasNoErrors();
    $driver = Driver::query()->where('callsign', '777')->firstOrFail();
    expect($driver->user->phone)->toBe('+998905554433')
        ->and($driver->vehicle->plate_number)->toBe('01123ABC');
});

test('existing plate in an old format can be kept when saving the driver', function () {
    $this->actingAs($this->admin)->post(route('admin.drivers.store'), formatDriverPayload($this->branch))->assertSessionHasNoErrors();
    $driver = Driver::query()->where('callsign', '777')->firstOrFail();
    $driver->vehicle->forceFill(['plate_number' => 'T1234'])->save();

    $this->put(route('admin.drivers.update', $driver), formatDriverPayload($this->branch, ['password' => null], ['plate_number' => 't 1234']))
        ->assertSessionHasNoErrors();
    expect($driver->fresh()->vehicle->plate_number)->toBe('T1234');
});

test('staff and company phones are validated and stored as E.164', function () {
    $this->actingAs($this->admin)->post(route('admin.staff.store'), [
        'name' => 'Operator', 'username' => 'op1', 'phone' => '91 234', 'password' => 'Secret-123456',
        'role' => 'operator', 'branch_id' => $this->branch->id,
    ])->assertSessionHasErrors('phone');

    $this->post(route('admin.companies.store'), [
        'branch_id' => $this->branch->id, 'name' => 'Artel', 'contact_phone' => '8 (91) 234-56-78', 'discount_percent' => 5,
    ])->assertSessionHasNoErrors();
    expect(Company::query()->where('name', 'Artel')->value('contact_phone'))->toBe('+998912345678');
});

test('a newly created driver gets an active SIP endpoint so in-app calls work at once', function () {
    $this->actingAs($this->admin)->post(route('admin.drivers.store'), formatDriverPayload($this->branch))
        ->assertSessionHasNoErrors();

    $user = Driver::query()->where('callsign', '777')->firstOrFail()->user;
    expect(PsEndpoint::query()->whereKey($user->sip_extension)->value('context'))->toBe('from-internal');
});

test('driver licence and tech passport need a letter series and a number', function () {
    $this->actingAs($this->admin)->post(route('admin.drivers.store'), formatDriverPayload($this->branch, [
        'license_no' => '12345',
    ], ['tech_passport_no' => 'ABCDEF']))->assertSessionHasErrors(['license_no', 'vehicle.tech_passport_no']);

    $this->post(route('admin.drivers.store'), formatDriverPayload($this->branch, [
        'license_no' => 'AF 1234567',
    ], ['tech_passport_no' => 'AAF1234567']))->assertSessionHasNoErrors();
});
