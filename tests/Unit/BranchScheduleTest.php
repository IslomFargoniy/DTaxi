<?php

use App\Models\Branch;
use Carbon\CarbonImmutable;

function branchWith(?array $schedule, array $holidays = []): Branch
{
    return new Branch(['timezone' => 'Asia/Tashkent', 'work_schedule' => $schedule, 'holidays' => $holidays]);
}

// Vaqtlar Toshkent vaqtida beriladi
function tashkent(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Tashkent');
}

test('branch without schedule works around the clock', function () {
    expect(branchWith(null)->isOpen(tashkent('2026-10-19 03:00')))->toBeTrue();
});

test('day hours and days off', function () {
    $branch = branchWith(['mon' => ['from' => '07:00', 'to' => '23:00'], 'sun' => null]);

    expect($branch->isOpen(tashkent('2026-10-19 08:00')))->toBeTrue()   // dushanba
        ->and($branch->isOpen(tashkent('2026-10-19 23:30')))->toBeFalse()
        ->and($branch->isOpen(tashkent('2026-10-18 12:00')))->toBeFalse(); // yakshanba — dam
});

test('night shift continues after midnight', function () {
    $branch = branchWith(['mon' => ['from' => '20:00', 'to' => '06:00'], 'tue' => null]);

    expect($branch->isOpen(tashkent('2026-10-19 22:00')))->toBeTrue()
        ->and($branch->isOpen(tashkent('2026-10-20 05:30')))->toBeTrue()
        ->and($branch->isOpen(tashkent('2026-10-20 07:00')))->toBeFalse();
});

test('holidays are closed', function () {
    expect(branchWith(null, ['2027-01-01'])->isOpen(tashkent('2027-01-01 12:00')))->toBeFalse();
});
