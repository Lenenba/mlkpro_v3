<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\PlatformAnnouncement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function announcementMediaSuperadmin(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role'],
    );

    return User::query()->create([
        'name' => 'Announcement Superadmin',
        'email' => 'announcement-superadmin@example.com',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);
}

function announcementMediaPayload(array $overrides = []): array
{
    return array_replace([
        'title' => 'Nouvelle plateforme',
        'body' => 'Decouvrez les nouveautes.',
        'status' => 'active',
        'audience' => 'all',
        'placement' => 'internal',
        'display_style' => 'standard',
        'background_color' => '#ffffff',
        'priority' => 10,
        'starts_at' => null,
        'ends_at' => null,
        'new_tenant_days' => null,
        'media_type' => 'none',
        'media_url' => null,
        'link_label' => null,
        'link_url' => null,
        'tenant_ids' => [],
    ], $overrides);
}

it('rejects media only announcements without an image or video on web and api', function () {
    $superadmin = announcementMediaSuperadmin();
    $payload = announcementMediaPayload([
        'display_style' => 'media_only',
        'background_color' => '#123456',
    ]);

    $this->actingAs($superadmin)
        ->post(route('superadmin.announcements.store'), $payload)
        ->assertSessionHasErrors('display_style');

    expect(PlatformAnnouncement::query()->count())->toBe(0);

    expect(trans('ui.announcements.media_only_requires_media', locale: 'fr'))
        ->toBe('Le mode « Média plein cadre » nécessite une image ou une vidéo.')
        ->and(trans('ui.announcements.media_only_requires_media', locale: 'es'))
        ->toBe('El modo «Medio a ancho completo» requiere una imagen o un vídeo.');

    app()->setLocale('en');
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/v1/super-admin/announcements', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('display_style')
        ->assertJsonPath(
            'errors.display_style.0',
            trans('ui.announcements.media_only_requires_media', locale: 'en'),
        );

    expect(PlatformAnnouncement::query()->count())->toBe(0);
});

it('persists media only announcements with media and removes their unused background', function () {
    $superadmin = announcementMediaSuperadmin();

    $this->actingAs($superadmin)
        ->post(route('superadmin.announcements.store'), announcementMediaPayload([
            'display_style' => 'media_only',
            'background_color' => '#123456',
            'media_type' => 'image',
            'media_url' => 'https://cdn.example.com/announcement.webp',
        ]))
        ->assertRedirect();

    $announcement = PlatformAnnouncement::query()->sole();

    expect($announcement->display_style)->toBe('media_only')
        ->and($announcement->media_type)->toBe('image')
        ->and($announcement->getRawOriginal('media_url'))->toBe('https://cdn.example.com/announcement.webp')
        ->and($announcement->background_color)->toBeNull();
});

it('accepts an api payload without media_url when the standard display is selected', function () {
    $superadmin = announcementMediaSuperadmin();
    $payload = announcementMediaPayload();
    unset($payload['media_url']);

    Sanctum::actingAs($superadmin);

    $this->postJson('/api/v1/super-admin/announcements', $payload)
        ->assertCreated();

    $announcement = PlatformAnnouncement::query()->sole();

    expect($announcement->display_style)->toBe('standard')
        ->and($announcement->media_type)->toBe('none')
        ->and($announcement->getRawOriginal('media_url'))->toBeNull();
});

it('accepts media only through api when a typed media url is provided', function () {
    $superadmin = announcementMediaSuperadmin();
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/v1/super-admin/announcements', announcementMediaPayload([
        'display_style' => 'media_only',
        'background_color' => '#123456',
        'media_type' => 'video',
        'media_url' => 'https://cdn.example.com/announcement.mp4',
    ]))->assertCreated();

    $announcement = PlatformAnnouncement::query()->sole();

    expect($announcement->display_style)->toBe('media_only')
        ->and($announcement->media_type)->toBe('video')
        ->and($announcement->getRawOriginal('media_url'))->toBe('https://cdn.example.com/announcement.mp4')
        ->and($announcement->background_color)->toBeNull();
});

it('keeps an existing file when clearing it would invalidate media only display', function () {
    Storage::fake('public');
    Storage::disk('public')->put('platform-announcements/existing.jpg', 'existing-image');

    $superadmin = announcementMediaSuperadmin();
    $announcement = PlatformAnnouncement::query()->create([
        ...announcementMediaPayload([
            'display_style' => 'standard',
            'media_type' => 'image',
            'media_path' => 'platform-announcements/existing.jpg',
        ]),
        'created_by' => $superadmin->id,
    ]);

    $this->actingAs($superadmin)
        ->put(route('superadmin.announcements.update', $announcement), announcementMediaPayload([
            'display_style' => 'media_only',
            'media_type' => 'image',
            'media_url' => null,
        ]))
        ->assertRedirect();

    expect($announcement->fresh()->media_path)->toBe('platform-announcements/existing.jpg')
        ->and($announcement->fresh()->display_style)->toBe('media_only')
        ->and($announcement->fresh()->background_color)->toBeNull();

    $this->actingAs($superadmin)
        ->put(route('superadmin.announcements.update', $announcement), announcementMediaPayload([
            'display_style' => 'media_only',
            'media_type' => 'none',
            'media_url' => null,
            'clear_media' => true,
        ]))
        ->assertSessionHasErrors('display_style');

    Storage::disk('public')->assertExists('platform-announcements/existing.jpg');

    expect($announcement->fresh()->media_path)->toBe('platform-announcements/existing.jpg')
        ->and($announcement->fresh()->media_type)->toBe('image')
        ->and($announcement->fresh()->display_style)->toBe('media_only');
});

it('validates api clear media before deleting the existing file', function () {
    Storage::fake('public');
    Storage::disk('public')->put('platform-announcements/api-existing.mp4', 'existing-video');

    $superadmin = announcementMediaSuperadmin();
    $announcement = PlatformAnnouncement::query()->create([
        ...announcementMediaPayload([
            'display_style' => 'media_only',
            'background_color' => null,
            'media_type' => 'video',
            'media_path' => 'platform-announcements/api-existing.mp4',
        ]),
        'created_by' => $superadmin->id,
    ]);

    Sanctum::actingAs($superadmin);

    $this->putJson('/api/v1/super-admin/announcements/'.$announcement->id, announcementMediaPayload([
        'display_style' => 'media_only',
        'media_type' => 'none',
        'media_url' => null,
        'clear_media' => true,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('display_style');

    Storage::disk('public')->assertExists('platform-announcements/api-existing.mp4');
    expect($announcement->fresh()->media_path)->toBe('platform-announcements/api-existing.mp4');

    $this->putJson('/api/v1/super-admin/announcements/'.$announcement->id, announcementMediaPayload([
        'display_style' => 'standard',
        'media_type' => 'none',
        'media_url' => null,
        'clear_media' => true,
    ]))->assertOk();

    Storage::disk('public')->assertMissing('platform-announcements/api-existing.mp4');
    expect($announcement->fresh()->media_path)->toBeNull()
        ->and($announcement->fresh()->media_type)->toBe('none')
        ->and($announcement->fresh()->display_style)->toBe('standard');
});

it('orders preview announcements deterministically by creation time and id', function () {
    $superadmin = announcementMediaSuperadmin();
    $createdAt = now()->subHour()->startOfSecond();

    $first = PlatformAnnouncement::query()->create([
        ...announcementMediaPayload(['title' => 'First']),
        'created_by' => $superadmin->id,
    ]);
    $second = PlatformAnnouncement::query()->create([
        ...announcementMediaPayload(['title' => 'Second']),
        'created_by' => $superadmin->id,
    ]);

    $first->forceFill(['created_at' => $createdAt])->saveQuietly();
    $second->forceFill(['created_at' => $createdAt])->saveQuietly();

    expect(PlatformAnnouncement::query()->orderedForDisplay()->pluck('id')->all())
        ->toBe([$second->id, $first->id]);

    $this->actingAs($superadmin)
        ->get(route('superadmin.announcements.preview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/Announcements/Preview')
            ->where('topAnnouncements.0.id', $second->id)
            ->where('topAnnouncements.1.id', $first->id));
});
