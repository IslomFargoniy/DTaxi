<?php

use App\Services\Sounds\MessageComposer;

test('numbers are spoken the Uzbek way', function (int $n, array $expected) {
    expect(array_map(fn ($p) => $p[1], (new MessageComposer)->number($n)))->toBe($expected);
})->with([
    [0, ['0']],
    [7, ['7']],
    [15, ['10', '5']],
    [40, ['40']],
    [123, ['1', '100', '20', '3']],
    [505, ['5', '100', '5']],
    [1500, ['1', '1000', '5', '100']],
]);

test('plate numbers: region code digit by digit, main number as a number, letters apart', function (string $plate, array $expected) {
    $spoken = array_map(fn ($p) => $p[0] === 'letter' ? strtoupper($p[1]) : $p[1], (new MessageComposer)->plate($plate));

    expect($spoken)->toBe($expected);
})->with([
    ['01 A 123 BC', ['0', '1', 'A', '1', '100', '20', '3', 'B', 'C']],
    ['01 123 ABC', ['0', '1', '1', '100', '20', '3', 'A', 'B', 'C']],
    ['40a005aa', ['4', '0', 'A', '5', 'A', 'A']],
]);
