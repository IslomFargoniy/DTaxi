<?php

use App\Services\Pricing\FareCalculator;
use Carbon\CarbonImmutable;

function tariff(array $overrides = []): array
{
    return [
        'base_fare' => 10000, 'base_km' => '3.00', 'per_km' => 2000, 'rounding_step' => 500,
        'waiting_enabled' => false, 'waiting_free_min' => 3, 'waiting_per_min' => 500,
        'night_enabled' => false, 'night_from' => '23:00:00', 'night_to' => '06:00:00', 'night_coeff' => '1.20',
        'outside_enabled' => false, 'outside_per_km' => 3000,
        ...$overrides,
    ];
}

test('short trip costs the base fare', function () {
    expect((new FareCalculator)->calculate(tariff(), 2500)->total)->toBe(10000);
});

test('plan example: 7.4 km is 18 800 rounded up to 19 000', function () {
    $fare = (new FareCalculator)->calculate(tariff(), 7400);

    expect($fare->total)->toBe(19000)
        ->and($fare->breakdown['distance'])->toBe(8800)
        ->and($fare->breakdown['rounding'])->toBe(200);
});

test('rounding goes up to the configured step', function (int $step, int $expected) {
    expect((new FareCalculator)->calculate(tariff(['rounding_step' => $step]), 7400)->total)->toBe($expected);
})->with([[500, 19000], [1000, 19000], [100, 18800], [1, 18800]]);

test('waiting is charged only after the free minutes when enabled', function () {
    $calculator = new FareCalculator;

    expect($calculator->calculate(tariff(), 2000, waitingSeconds: 600)->total)->toBe(10000);

    $fare = $calculator->calculate(tariff(['waiting_enabled' => true]), 2000, waitingSeconds: 5 * 60 + 10);
    // 5 daq 10 s → 6 daq, 3 tasi bepul → 3 × 500
    expect($fare->breakdown['waiting'])->toBe(1500)->and($fare->total)->toBe(11500);
});

test('night coefficient applies across midnight when enabled', function (string $time, int $expected) {
    $fare = (new FareCalculator)->calculate(
        tariff(['night_enabled' => true]),
        2000,
        startedAt: CarbonImmutable::parse("2026-10-18 {$time}", 'Asia/Tashkent'),
    );

    expect($fare->total)->toBe($expected);
})->with([['23:30', 12000], ['02:00', 12000], ['05:59', 12000], ['06:00', 10000], ['12:00', 10000]]);

test('outside kilometres use their own price when enabled', function () {
    $calculator = new FareCalculator;

    // O'chiq: 4 km shahar + 2 km tashqari = 6 km oddiy narxda
    expect($calculator->calculate(tariff(), 4000, 2000)->total)->toBe(16000);

    // Yoqilgan: 1 km × 2000 + 2 km × 3000
    $fare = $calculator->calculate(tariff(['outside_enabled' => true]), 4000, 2000);
    expect($fare->breakdown)->toMatchArray(['distance' => 2000, 'outside' => 6000])
        ->and($fare->total)->toBe(18000);
});

test('all rules together', function () {
    $fare = (new FareCalculator)->calculate(
        tariff(['waiting_enabled' => true, 'night_enabled' => true, 'outside_enabled' => true]),
        insideMeters: 5000,
        outsideMeters: 1000,
        waitingSeconds: 4 * 60,
        startedAt: CarbonImmutable::parse('2026-10-18 23:15'),
    );

    // 10000 + 2×2000 + 1×3000 + 1×500 = 17500; ×1.2 = 21000
    expect($fare->total)->toBe(21000)->and($fare->breakdown['night'])->toBe(3500);
});
