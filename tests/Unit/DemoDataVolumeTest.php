<?php

use App\Enums\DemoDataVolume;

test('demo data volumes normalize current and legacy values', function () {
    expect(DemoDataVolume::normalize('small'))->toBe(DemoDataVolume::Small)
        ->and(DemoDataVolume::normalize('MEDIUM'))->toBe(DemoDataVolume::Medium)
        ->and(DemoDataVolume::normalize(' large '))->toBe(DemoDataVolume::Large)
        ->and(DemoDataVolume::fromLegacyProfile('light'))->toBe(DemoDataVolume::Small)
        ->and(DemoDataVolume::fromLegacyProfile('standard'))->toBe(DemoDataVolume::Medium)
        ->and(DemoDataVolume::fromLegacyProfile('immersive'))->toBe(DemoDataVolume::Large)
        ->and(DemoDataVolume::normalize(null))->toBe(DemoDataVolume::Medium);
});

test('demo data volumes select their configured count', function () {
    expect(DemoDataVolume::Small->select(10, 20, 30))->toBe(10)
        ->and(DemoDataVolume::Medium->select(10, 20, 30))->toBe(20)
        ->and(DemoDataVolume::Large->select(10, 20, 30))->toBe(30);
});

test('demo data volumes reject unsupported values', function () {
    expect(fn () => DemoDataVolume::normalize('huge'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported demo data volume [huge].');
});
