<?php

use App\Support\TipCalculator;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

test('it returns no tip payload when tip is disabled', function () {
    $result = TipCalculator::resolve(120.55, [
        'tip_enabled' => false,
        'tip_mode' => 'none',
    ]);

    expect($result['tip_type'])->toBe('none');
    expect($result['tip_percent'])->toBeNull();
    expect($result['tip_amount'])->toBe(0.0);
    expect($result['tip_base_amount'])->toBe(120.55);
    expect($result['charged_total'])->toBe(120.55);
});

test('it resolves percent tip with rounded amount and metadata', function () {
    config()->set('tips.max_percent', 30);

    $result = TipCalculator::resolve(99.99, [
        'tip_enabled' => true,
        'tip_mode' => 'percent',
        'tip_percent' => 12.5,
    ]);

    expect($result['tip_type'])->toBe('percent');
    expect($result['tip_percent'])->toBe(12.5);
    expect($result['tip_amount'])->toBe(12.5);
    expect($result['tip_base_amount'])->toBe(99.99);
    expect($result['charged_total'])->toBe(112.49);
});

test('it resolves fixed tip with metadata', function () {
    config()->set('tips.max_fixed_amount', 200);

    $result = TipCalculator::resolve(80, [
        'tip_enabled' => true,
        'tip_mode' => 'fixed',
        'tip_amount' => 9.75,
    ]);

    expect($result['tip_type'])->toBe('fixed');
    expect($result['tip_percent'])->toBeNull();
    expect($result['tip_amount'])->toBe(9.75);
    expect($result['tip_base_amount'])->toBe(80.0);
    expect($result['charged_total'])->toBe(89.75);
});

test('it rejects tip percent above configured maximum', function () {
    config()->set('tips.max_percent', 15);

    $exception = null;
    try {
        TipCalculator::resolve(100, [
            'tip_mode' => 'percent',
            'tip_percent' => 18,
        ]);
    } catch (ValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->errors())->toHaveKey('tip_percent');
});

test('it rejects fixed tip above configured maximum', function () {
    config()->set('tips.max_fixed_amount', 25);

    $exception = null;
    try {
        TipCalculator::resolve(100, [
            'tip_mode' => 'fixed',
            'tip_amount' => 30,
        ]);
    } catch (ValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->errors())->toHaveKey('tip_amount');
});

test('it uses account level limits when provided', function () {
    $exception = null;
    try {
        TipCalculator::resolve(100, [
            'tip_mode' => 'percent',
            'tip_percent' => 22,
        ], [
            'max_percent' => 20,
            'max_fixed_amount' => 250,
            'default_percent' => 10,
            'quick_percents' => [5, 10],
            'quick_fixed_amounts' => [2, 5],
            'allocation_strategy' => 'primary',
            'partial_refund_rule' => 'prorata',
        ]);
    } catch (ValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->errors())->toHaveKey('tip_percent');
});
