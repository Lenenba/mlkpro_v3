<?php

use App\Data\Social\SocialDeliveryStatusResultData;
use App\Services\Social\SocialReconciliationCadence;
use Carbon\CarbonImmutable;

it('uses the normalized polling cadence around a scheduled delivery', function (
    int $secondsFromNow,
    ?int $expectedMinutes,
) {
    $now = CarbonImmutable::parse('2026-08-28 12:00:00', 'UTC');
    $next = (new SocialReconciliationCadence)->nextAt(
        SocialDeliveryStatusResultData::STATUS_SCHEDULED,
        $now->addSeconds($secondsFromNow),
        1,
        $now,
    );

    expect($next?->toIso8601String())->toBe(
        $expectedMinutes !== null
            ? $now->addMinutes($expectedMinutes)->toIso8601String()
            : null,
    );
})->with([
    'more than 24 hours' => [25 * 60 * 60, 24 * 60],
    'exactly 24 hours' => [24 * 60 * 60, 120],
    'more than 2 hours' => [3 * 60 * 60, 120],
    'exactly 2 hours' => [2 * 60 * 60, 15],
    'within 2 hours' => [60 * 60, 15],
    'at delivery time' => [0, 5],
    'within the late grace window' => [-29 * 60, 5],
    'after the late grace window' => [-31 * 60, null],
]);

it('caps sending and unknown polling while stopping terminal or divergent states', function () {
    $now = CarbonImmutable::parse('2026-08-28 12:00:00', 'UTC');
    $cadence = new SocialReconciliationCadence;

    expect($cadence->nextAt(
        SocialDeliveryStatusResultData::STATUS_SENDING,
        null,
        4,
        $now,
    )?->toIso8601String())->toBe($now->addMinutes(2)->toIso8601String())
        ->and($cadence->nextAt(
            SocialDeliveryStatusResultData::STATUS_SENDING,
            null,
            5,
            $now,
        ))->toBeNull()
        ->and($cadence->nextAt(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            null,
            2,
            $now,
        )?->toIso8601String())->toBe($now->addMinutes(5)->toIso8601String())
        ->and($cadence->nextAt(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            null,
            3,
            $now,
        ))->toBeNull();

    foreach ([
        SocialDeliveryStatusResultData::STATUS_SENT,
        SocialDeliveryStatusResultData::STATUS_ERROR,
        SocialDeliveryStatusResultData::STATUS_DRAFT,
        SocialDeliveryStatusResultData::STATUS_APPROVAL_REQUIRED,
    ] as $status) {
        expect($cadence->nextAt($status, null, 1, $now))->toBeNull();
    }
});

it('reserves the final bounded read but rejects every read beyond the ceiling', function () {
    $cadence = new SocialReconciliationCadence;

    expect($cadence->canReserve(
        SocialDeliveryStatusResultData::STATUS_UNKNOWN,
        2,
    ))->toBeTrue()
        ->and($cadence->canReserve(
            SocialDeliveryStatusResultData::STATUS_UNKNOWN,
            3,
        ))->toBeFalse()
        ->and($cadence->canReserve(
            SocialDeliveryStatusResultData::STATUS_SENDING,
            4,
        ))->toBeTrue()
        ->and($cadence->canReserve(
            SocialDeliveryStatusResultData::STATUS_SENDING,
            5,
        ))->toBeFalse()
        ->and($cadence->canReserve(
            SocialDeliveryStatusResultData::STATUS_SCHEDULED,
            99,
        ))->toBeTrue();
});
