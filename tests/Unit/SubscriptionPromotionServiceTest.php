<?php

use App\Models\SubscriptionPromotion;
use App\Services\SubscriptionPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('calculates discounted subscription price presentations', function () {
    SubscriptionPromotion::query()->updateOrCreate(
        ['key' => SubscriptionPromotion::GLOBAL_KEY],
        [
            'name' => 'Global subscription promotion',
            'is_enabled' => true,
            'monthly_discount_percent' => 25,
            'yearly_discount_percent' => 35,
        ]
    );

    $monthlyPresentation = app(SubscriptionPromotionService::class)->pricePresentation('100.00', 'CAD');
    $yearlyPresentation = app(SubscriptionPromotionService::class)->pricePresentation('1200.00', 'CAD', false, 'yearly');

    expect($monthlyPresentation['amount'])->toBe('100.00')
        ->and($monthlyPresentation['original_amount'])->toBe('100.00')
        ->and($monthlyPresentation['discounted_amount'])->toBe('75.00')
        ->and($monthlyPresentation['is_discounted'])->toBeTrue()
        ->and($monthlyPresentation['promotion']['is_active'])->toBeTrue()
        ->and($monthlyPresentation['promotion']['discount_percent'])->toBe(25)
        ->and($yearlyPresentation['amount'])->toBe('1200.00')
        ->and($yearlyPresentation['original_amount'])->toBe('1200.00')
        ->and($yearlyPresentation['discounted_amount'])->toBe('780.00')
        ->and($yearlyPresentation['is_discounted'])->toBeTrue()
        ->and($yearlyPresentation['promotion']['is_active'])->toBeTrue()
        ->and($yearlyPresentation['promotion']['discount_percent'])->toBe(35);
});

it('supports enabling only one billing period discount at a time', function () {
    SubscriptionPromotion::query()->updateOrCreate(
        ['key' => SubscriptionPromotion::GLOBAL_KEY],
        [
            'name' => 'Global subscription promotion',
            'is_enabled' => true,
            'monthly_discount_percent' => null,
            'yearly_discount_percent' => 30,
        ]
    );

    $monthlyPresentation = app(SubscriptionPromotionService::class)->pricePresentation('100.00', 'CAD');
    $yearlyPresentation = app(SubscriptionPromotionService::class)->pricePresentation('1200.00', 'CAD', false, 'yearly');

    expect($monthlyPresentation['discounted_amount'])->toBe('100.00')
        ->and($monthlyPresentation['is_discounted'])->toBeFalse()
        ->and($monthlyPresentation['promotion']['discount_percent'])->toBeNull()
        ->and($yearlyPresentation['discounted_amount'])->toBe('840.00')
        ->and($yearlyPresentation['is_discounted'])->toBeTrue()
        ->and($yearlyPresentation['promotion']['discount_percent'])->toBe(30);
});

it('skips subscription discounts for contact only pricing', function () {
    SubscriptionPromotion::query()->updateOrCreate(
        ['key' => SubscriptionPromotion::GLOBAL_KEY],
        [
            'name' => 'Global subscription promotion',
            'is_enabled' => true,
            'monthly_discount_percent' => 30,
            'yearly_discount_percent' => 45,
        ]
    );

    $presentation = app(SubscriptionPromotionService::class)->pricePresentation('100.00', 'CAD', true);

    expect($presentation['amount'])->toBe('100.00')
        ->and($presentation['discounted_amount'])->toBe('100.00')
        ->and($presentation['is_discounted'])->toBeFalse()
        ->and($presentation['promotion']['is_active'])->toBeFalse()
        ->and($presentation['promotion']['discount_percent'])->toBeNull();
});
