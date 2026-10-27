<?php

use App\Support\Csv;

test('csv cells starting with formula characters are neutralised, numbers stay numbers', function () {
    expect(Csv::row(['=HYPERLINK("x")', '+998901234567', '-5', '@SUM(A1)', 'Ali', 42, -5, null, '']))
        ->toBe(["'=HYPERLINK(\"x\")", "'+998901234567", "'-5", "'@SUM(A1)", 'Ali', 42, -5, null, '']);
});
