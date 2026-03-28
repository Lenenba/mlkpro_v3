<?php

use App\Support\CurrencyFormatter;

it('formats usd amounts with the dollar symbol before the amount', function () {
    $formatted = CurrencyFormatter::format(22, 'USD');

    expect($formatted)
        ->toContain('$')
        ->toMatch('/^\D+22(?:[.,]00)?/u');
});

it('formats eur amounts with the euro symbol after the amount', function () {
    $formatted = CurrencyFormatter::format(19, 'EUR');

    expect($formatted)
        ->toContain('€')
        ->toMatch('/19(?:[.,]00)?\h*€$/u');
});

it('falls back to cad when an invalid currency code is provided', function () {
    $formatted = CurrencyFormatter::format(30, 'XYZ');

    expect($formatted)
        ->toContain('$')
        ->not->toContain('€');
});
