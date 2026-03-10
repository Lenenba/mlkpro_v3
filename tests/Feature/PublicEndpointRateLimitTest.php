<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ReservationSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

function phase7PublicRateRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function phase7CreateRateOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Phase7 Owner',
        'email' => 'phase7-owner-'.Str::lower(Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => phase7PublicRateRoleId('owner'),
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'requests' => true,
            'reservations' => true,
        ],
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function phase7EnableKioskQueue(User $owner): void
{
    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 10,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );
}

function phase7KioskSignedRoute(string $name, User $owner): string
{
    return URL::signedRoute($name, ['account' => $owner->id]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('rate limits public lead lookup endpoints per tenant and ip', function () {
    config()->set('services.rate_limits.public_lead_lookup_per_minute', 2);

    $owner = phase7CreateRateOwner([
        'company_features' => ['requests' => true],
    ]);

    $url = URL::signedRoute('public.requests.suggest', ['user' => $owner->id]);
    $payload = [
        'service_type' => 'Website',
        'description' => 'Need a new website with booking.',
    ];

    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertStatus(429);
});

it('rate limits public lead submissions per tenant and email fingerprint', function () {
    Notification::fake();
    config()->set('services.rate_limits.public_lead_submit_per_minute', 1);

    $owner = phase7CreateRateOwner([
        'company_features' => ['requests' => true],
    ]);

    $url = URL::signedRoute('public.requests.store', ['user' => $owner->id]);
    $payload = [
        'contact_name' => 'Prospect Phase 7',
        'contact_email' => 'prospect.phase7@example.com',
        'service_type' => 'Consulting',
        'description' => 'Need a qualification call.',
    ];

    $this->post($url, $payload)->assertRedirect();
    $this->post($url, $payload)->assertStatus(429);
});

it('rate limits public kiosk endpoints per tenant and ip', function () {
    config()->set('services.rate_limits.public_kiosk_per_minute', 1);

    $owner = phase7CreateRateOwner([
        'company_features' => ['reservations' => true],
    ]);
    phase7EnableKioskQueue($owner);

    $url = phase7KioskSignedRoute('public.kiosk.reservations.show', $owner);

    $this->get($url)->assertOk();
    $this->get($url)->assertStatus(429);
});

it('rate limits ai image generation requests per account', function () {
    config()->set('services.rate_limits.ai_images_per_minute', 2);
    config()->set('services.openai.key', null);

    $owner = phase7CreateRateOwner();

    $payload = [
        'prompt' => 'Generate a logo concept.',
        'context' => 'store',
    ];

    $this->actingAs($owner)
        ->postJson(route('ai.images.generate'), $payload)
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson(route('ai.images.generate'), $payload)
        ->assertStatus(422);

    $this->actingAs($owner)
        ->postJson(route('ai.images.generate'), $payload)
        ->assertStatus(429);
});
