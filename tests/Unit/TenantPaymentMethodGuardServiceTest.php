<?php

use App\Services\TenantPaymentMethodGuardService;

test('guard allows stripe when card is enabled by default', function () {
    $decision = app(TenantPaymentMethodGuardService::class)->evaluate(0, 'stripe', 'invoice_portal');

    expect($decision['allowed'])->toBeTrue()
        ->and($decision['canonical_method'])->toBe('card')
        ->and($decision['normalized_business_method'])->toBe('stripe');
});

test('guard rejects unsupported other method when not enabled', function () {
    $decision = app(TenantPaymentMethodGuardService::class)->evaluate(0, 'other', 'invoice_manual');

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['error_code'])->toBe(TenantPaymentMethodGuardService::ERROR_CODE)
        ->and($decision['error_message'])->toBe(TenantPaymentMethodGuardService::ERROR_MESSAGE);
});

test('guard falls back to default method when request method is missing', function () {
    $decision = app(TenantPaymentMethodGuardService::class)->evaluate(0, null, 'sale_manual');

    expect($decision['allowed'])->toBeTrue()
        ->and($decision['canonical_method'])->toBe('cash')
        ->and($decision['default_method_internal'])->toBe('cash');
});

test('guard rejects unknown raw method', function () {
    $decision = app(TenantPaymentMethodGuardService::class)->evaluate(0, 'crypto', 'invoice_public');

    expect($decision['allowed'])->toBeFalse()
        ->and($decision['error_code'])->toBe(TenantPaymentMethodGuardService::ERROR_CODE);
});

