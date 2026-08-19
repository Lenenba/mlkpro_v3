<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('company settings expose and update the normalized primary brand palette', function () {
    $owner = User::factory()->create([
        'company_name' => 'Palette Company',
        'company_type' => 'services',
        'company_branding_settings' => [
            'primary_color' => '#123ABC',
            'future_key' => 'preserved',
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('settings.company.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.branding_settings.primary_color', '#123ABC')
            ->where('company.branding_settings.effective_primary_color', '#123ABC')
            ->where('company.branding_settings.primary_hover_color', '#1033A5')
            ->where('company.branding_settings.primary_focus_color', '#0E2D93')
            ->where('company.branding_settings.primary_foreground_color', '#FFFFFF')
            ->where('company.branding_settings.has_custom_primary_color', true));

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->put(route('settings.company.update'), [
            'company_name' => 'Palette Company',
            'company_type' => 'services',
            'company_branding_settings' => [
                'primary_color' => '#abcdef',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($owner->fresh()->company_branding_settings)->toBe([
        'primary_color' => '#ABCDEF',
        'future_key' => 'preserved',
    ]);
});

test('company settings preserve an omitted brand and reset an explicit null primary color', function () {
    $owner = User::factory()->create([
        'company_name' => 'Persistent Palette',
        'company_type' => 'services',
        'company_branding_settings' => [
            'primary_color' => '#123ABC',
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->put(route('settings.company.update'), [
            'company_name' => 'Persistent Palette',
            'company_type' => 'services',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($owner->fresh()->company_branding_settings)->toBe([
        'primary_color' => '#123ABC',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->put(route('settings.company.update'), [
            'company_name' => 'Persistent Palette',
            'company_type' => 'services',
            'company_branding_settings' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($owner->fresh()->company_branding_settings)->toBeNull();
});

test('company settings reject malformed colors and unknown branding keys', function (array $branding, string $errorKey) {
    $owner = User::factory()->create([
        'company_name' => 'Protected Palette',
        'company_type' => 'services',
        'company_branding_settings' => [
            'primary_color' => '#123ABC',
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('settings.company.edit'))
        ->put(route('settings.company.update'), [
            'company_name' => 'Protected Palette',
            'company_type' => 'services',
            'company_branding_settings' => $branding,
        ])
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHasErrors($errorKey);

    expect($owner->fresh()->company_branding_settings)->toBe([
        'primary_color' => '#123ABC',
    ]);
})->with([
    'malformed primary color' => [
        ['primary_color' => '#123456; color: red'],
        'company_branding_settings.primary_color',
    ],
    'unknown branding key' => [
        ['primary_color' => '#123456', 'secondary_color' => '#654321'],
        'company_branding_settings',
    ],
]);
