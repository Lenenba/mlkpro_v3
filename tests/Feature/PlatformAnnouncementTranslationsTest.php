<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\PlatformAnnouncement;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function announcementTranslationSuperadmin(array $overrides = []): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role'],
    );

    return User::query()->create(array_replace([
        'name' => 'Translation Superadmin',
        'email' => 'announcement-translation-superadmin@example.com',
        'password' => 'password',
        'role_id' => $role->id,
        'locale' => 'fr',
        'onboarding_completed_at' => now(),
    ], $overrides));
}

function announcementTranslationPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => 'active',
        'audience' => 'all',
        'placement' => 'internal',
        'display_style' => 'standard',
        'priority' => 10,
        'media_type' => 'none',
        'tenant_ids' => [],
    ], $overrides);
}

it('resolves translated announcement fields independently before using legacy content', function () {
    $announcement = PlatformAnnouncement::query()->create([
        ...announcementTranslationPayload(),
        'title' => 'Legacy title',
        'body' => 'Legacy body',
        'link_label' => 'Legacy link',
        'translations' => [
            'fr' => [
                'title' => 'Titre français',
                'body' => 'Corps français',
            ],
            'en' => [
                'title' => 'English title',
                'link_label' => 'Read more',
            ],
            'es' => [
                'title' => 'Título español',
            ],
        ],
    ]);

    expect($announcement->localizedContent('es'))->toBe([
        'title' => 'Título español',
        'body' => 'Corps français',
        'link_label' => 'Read more',
    ])->and($announcement->localizedContent('en'))->toBe([
        'title' => 'English title',
        'body' => 'Corps français',
        'link_label' => 'Read more',
    ]);

    $announcement->translations = [];

    expect($announcement->localizedContent('es'))->toBe([
        'title' => 'Legacy title',
        'body' => 'Legacy body',
        'link_label' => 'Legacy link',
    ]);
});

it('stores multilingual web content while keeping legacy columns usable', function () {
    $superadmin = announcementTranslationSuperadmin();

    $this->actingAs($superadmin)
        ->post(route('superadmin.announcements.store'), announcementTranslationPayload([
            'translations' => [
                'fr' => [
                    'title' => 'Bienvenue',
                    'body' => 'Découvrez les nouveautés.',
                    'link_label' => 'En savoir plus',
                ],
                'en' => [
                    'title' => 'Welcome',
                    'body' => 'Discover what is new.',
                    'link_label' => 'Learn more',
                ],
                'es' => [
                    'title' => 'Bienvenido',
                    'body' => 'Descubre las novedades.',
                    'link_label' => 'Más información',
                ],
            ],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $announcement = PlatformAnnouncement::query()->sole();

    expect($announcement->title)->toBe('Bienvenue')
        ->and($announcement->body)->toBe('Découvrez les nouveautés.')
        ->and($announcement->link_label)->toBe('En savoir plus')
        ->and($announcement->translations['en']['title'])->toBe('Welcome')
        ->and($announcement->localizedContent('es')['title'])->toBe('Bienvenido');

    $this->actingAs($superadmin)
        ->get(route('superadmin.announcements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('content_locales', ['fr', 'en', 'es'])
            ->where('announcements.0.title', 'Bienvenue')
            ->where('announcements.0.translations.en.title', 'Welcome'));
});

it('keeps old api payloads valid and exposes translations alongside synchronized legacy fields', function () {
    $superadmin = announcementTranslationSuperadmin(['locale' => 'es']);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/v1/super-admin/announcements', announcementTranslationPayload([
        'title' => 'Legacy API title',
        'body' => 'Legacy API body',
        'link_label' => 'Legacy API link',
    ]))->assertCreated();

    $legacyAnnouncement = PlatformAnnouncement::query()->sole();

    expect($legacyAnnouncement->translations)->toBeNull()
        ->and($legacyAnnouncement->title)->toBe('Legacy API title');

    $this->putJson('/api/v1/super-admin/announcements/'.$legacyAnnouncement->id, announcementTranslationPayload([
        'translations' => [
            'fr' => ['title' => 'Titre API'],
            'en' => ['title' => 'API title'],
            'es' => ['title' => 'Título API'],
        ],
    ]))->assertOk();

    expect($legacyAnnouncement->fresh()->translations['es']['title'])->toBe('Título API')
        ->and($legacyAnnouncement->fresh()->title)->toBe('Titre API');

    $this->getJson('/api/v1/super-admin/announcements')
        ->assertOk()
        ->assertJsonPath('content_locales', ['fr', 'en', 'es'])
        ->assertJsonPath('announcements.0.title', 'Titre API')
        ->assertJsonPath('announcements.0.localized_title', 'Título API')
        ->assertJsonPath('announcements.0.translations.en.title', 'API title');
});

it('makes a legacy api update global instead of keeping stale translations', function () {
    $superadmin = announcementTranslationSuperadmin(['locale' => 'es']);
    Sanctum::actingAs($superadmin);

    $announcement = PlatformAnnouncement::query()->create([
        ...announcementTranslationPayload(),
        'title' => 'Titre initial',
        'body' => 'Corps initial',
        'translations' => [
            'fr' => ['title' => 'Titre français'],
            'en' => ['title' => 'English title'],
            'es' => ['title' => 'Título español'],
        ],
    ]);

    $this->putJson('/api/v1/super-admin/announcements/'.$announcement->id, announcementTranslationPayload([
        'title' => 'Global title from legacy API',
        'body' => 'Global body from legacy API',
    ]))->assertOk();

    $announcement->refresh();

    expect($announcement->translations)->toBeNull()
        ->and($announcement->localizedContent('fr')['title'])->toBe('Global title from legacy API')
        ->and($announcement->localizedContent('en')['title'])->toBe('Global title from legacy API')
        ->and($announcement->localizedContent('es')['title'])->toBe('Global title from legacy API');
});

it('preserves untouched legacy fallbacks during a partial translation api update', function () {
    $superadmin = announcementTranslationSuperadmin(['locale' => 'en']);
    Sanctum::actingAs($superadmin);

    $announcement = PlatformAnnouncement::query()->create([
        ...announcementTranslationPayload(),
        'title' => 'Legacy title',
        'body' => 'Legacy body',
        'link_label' => 'Legacy link',
        'translations' => [
            'fr' => ['title' => 'Titre français'],
        ],
    ]);

    $this->putJson('/api/v1/super-admin/announcements/'.$announcement->id, announcementTranslationPayload([
        'translations' => [
            'en' => ['title' => 'Updated English title'],
        ],
    ]))->assertOk();

    $announcement->refresh();

    expect($announcement->title)->toBe('Updated English title')
        ->and($announcement->body)->toBe('Legacy body')
        ->and($announcement->link_label)->toBe('Legacy link')
        ->and($announcement->localizedContent('en'))->toBe([
            'title' => 'Updated English title',
            'body' => 'Legacy body',
            'link_label' => 'Legacy link',
        ]);
});

it('rejects unsupported translation locales and announcements without any title', function () {
    $superadmin = announcementTranslationSuperadmin();

    $this->actingAs($superadmin)
        ->post(route('superadmin.announcements.store'), announcementTranslationPayload([
            'translations' => [
                'de' => ['title' => 'Willkommen'],
            ],
        ]))
        ->assertSessionHasErrors('translations');

    $this->actingAs($superadmin)
        ->post(route('superadmin.announcements.store'), announcementTranslationPayload([
            'translations' => [
                'fr' => ['body' => 'Un corps sans titre.'],
            ],
        ]))
        ->assertSessionHasErrors('title');

    expect(PlatformAnnouncement::query()->count())->toBe(0);
});

it('serializes dashboard and preview announcements in the authenticated user locale', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'locale' => 'es',
    ]);
    $superadmin = announcementTranslationSuperadmin([
        'email' => 'spanish-preview-superadmin@example.com',
        'locale' => 'es',
    ]);

    PlatformAnnouncement::query()->create([
        ...announcementTranslationPayload(),
        'title' => 'Legacy announcement',
        'body' => 'Legacy body',
        'link_label' => 'Legacy link',
        'created_by' => $superadmin->id,
        'translations' => [
            'fr' => [
                'title' => 'Annonce française',
                'body' => 'Corps français',
            ],
            'en' => [
                'title' => 'English announcement',
                'link_label' => 'Read more',
            ],
            'es' => [
                'title' => 'Anuncio español',
            ],
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('announcements.0.title', 'Anuncio español')
            ->where('announcements.0.body', 'Corps français')
            ->where('announcements.0.link_label', 'Read more'));

    $this->actingAs($superadmin)
        ->get(route('superadmin.announcements.preview'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('topAnnouncements.0.title', 'Anuncio español')
            ->where('topAnnouncements.0.body', 'Corps français')
            ->where('topAnnouncements.0.link_label', 'Read more'));
});
