<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\PlatformAdmin;
use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function platformSectionFeatureRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => ucfirst($name).' role']
    )->id;
}

function platformSectionAdmin(array $permissions = []): User
{
    $user = User::query()->create([
        'name' => 'Platform Admin',
        'email' => 'platform-sections-admin@example.com',
        'password' => 'password',
        'role_id' => platformSectionFeatureRoleId('admin'),
        'onboarding_completed_at' => now(),
    ]);

    PlatformAdmin::query()->create([
        'user_id' => $user->id,
        'role' => 'content',
        'permissions' => $permissions,
        'is_active' => true,
        'require_2fa' => false,
    ]);

    return $user;
}

it('duplicates reusable sections from the section library module', function () {
    $admin = platformSectionAdmin([PlatformPermissions::PAGES_MANAGE]);

    $section = PlatformSection::query()->create([
        'name' => 'Reusable feature tabs',
        'type' => 'feature_tabs',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'layout' => 'feature_tabs',
                    'title' => 'Un logiciel de gestion terrain qui travaille pour vous.',
                    'body' => '<p>Centralisez votre operation dans un seul flux.</p>',
                    'feature_tabs' => [
                        [
                            'id' => 'tab-1',
                            'label' => 'Planifier',
                            'icon' => 'calendar-days',
                            'items' => ['Calendrier glisser-deposer', 'Affectation equipe'],
                            'children' => [
                                [
                                    'id' => 'tab-1-child-1',
                                    'label' => 'Calendrier glisser-deposer',
                                    'title' => 'Planifiez chaque intervention sans friction',
                                    'body' => '<p>Organisez vos jobs et gardez toute l equipe synchronisee.</p>',
                                    'image_url' => 'https://example.com/feature-tabs-schedule.jpg',
                                    'image_alt' => 'Planning mobile',
                                    'cta_label' => 'Voir la planification',
                                    'cta_href' => '/pages/contact-us',
                                ],
                            ],
                            'title' => 'Planifiez chaque intervention sans friction',
                            'body' => '<p>Organisez vos jobs et gardez toute l equipe synchronisee.</p>',
                            'image_url' => 'https://example.com/feature-tabs-schedule.jpg',
                            'image_alt' => 'Planning mobile',
                            'cta_label' => 'Voir la planification',
                            'cta_href' => '/pages/contact-us',
                        ],
                    ],
                ],
                'en' => [
                    'layout' => 'feature_tabs',
                    'title' => 'Field service management software that works for you.',
                    'body' => '<p>Keep your entire operation in a single workflow.</p>',
                    'feature_tabs' => [
                        [
                            'id' => 'tab-1',
                            'label' => 'Schedule',
                            'icon' => 'calendar-days',
                            'items' => ['Drag-and-drop calendar', 'Team assignment'],
                            'children' => [
                                [
                                    'id' => 'tab-1-child-1',
                                    'label' => 'Drag-and-drop calendar',
                                    'title' => 'Schedule every job without friction',
                                    'body' => '<p>Organize jobs and keep your team aligned.</p>',
                                    'image_url' => 'https://example.com/feature-tabs-schedule.jpg',
                                    'image_alt' => 'Mobile schedule',
                                    'cta_label' => 'See scheduling',
                                    'cta_href' => '/pages/contact-us',
                                ],
                            ],
                            'title' => 'Schedule every job without friction',
                            'body' => '<p>Organize jobs and keep your team aligned.</p>',
                            'image_url' => 'https://example.com/feature-tabs-schedule.jpg',
                            'image_alt' => 'Mobile schedule',
                            'cta_label' => 'See scheduling',
                            'cta_href' => '/pages/contact-us',
                        ],
                    ],
                ],
            ],
            'updated_by' => null,
            'updated_at' => now()->subDay()->toIso8601String(),
        ],
    ]);

    $response = $this->actingAs($admin)
        ->post(route('superadmin.sections.duplicate', $section));

    $copy = PlatformSection::query()
        ->where('id', '!=', $section->id)
        ->latest('id')
        ->first();

    expect($copy)->not->toBeNull();

    $response
        ->assertRedirect(route('superadmin.sections.edit', $copy))
        ->assertSessionHas('success');

    expect($copy->type)->toBe('feature_tabs');
    expect($copy->name)->toStartWith('Reusable feature tabs');
    expect($copy->updated_by)->toBe($admin->id);
    expect(data_get($copy->content, 'locales.fr.feature_tabs.0.label'))->toBe('Planifier');
    expect(data_get($copy->content, 'locales.fr.feature_tabs.0.icon'))->toBe('calendar-days');
    expect(data_get($copy->content, 'locales.fr.feature_tabs.0.children.0.label'))->toBe('Calendrier glisser-deposer');
    expect(data_get($copy->content, 'locales.en.title'))->toBe('Field service management software that works for you.');
});

it('auto-creates the shared footer in the reusable section library', function () {
    $admin = platformSectionAdmin([PlatformPermissions::PAGES_MANAGE]);

    $this->actingAs($admin)
        ->get(route('superadmin.sections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/Sections/Index')
            ->where('sections', fn ($sections) => collect($sections)->contains(
                fn (array $section) => $section['type'] === 'footer' && $section['is_active'] === true
            ))
        );

    $footerName = PlatformSection::query()->where('type', 'footer')->value('name');

    expect(PlatformSection::query()->where('type', 'footer')->count())->toBe(1);
    expect(['Footer partage', 'Shared footer'])->toContain($footerName);
});

it('shows the shared footer card in the page editor while keeping it out of body section picks', function () {
    $admin = platformSectionAdmin([PlatformPermissions::PAGES_MANAGE]);

    PlatformSection::query()->create([
        'name' => 'Reusable testimonial',
        'type' => 'testimonial',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $this->actingAs($admin)
        ->get(route('superadmin.pages.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/Pages/Edit')
            ->where('footer_section.type', 'footer')
            ->where('footer_section.is_active', true)
            ->where('library_sections', fn ($sections) => collect($sections)->contains(
                fn (array $section) => $section['type'] === 'testimonial'
            ) && ! collect($sections)->contains(
                fn (array $section) => $section['type'] === 'footer'
            ))
        );

    expect(PlatformSection::query()->where('type', 'footer')->count())->toBe(1);
});

it('allows welcome-only admins to use the unified pages, sections, and assets modules', function () {
    $admin = platformSectionAdmin([PlatformPermissions::WELCOME_MANAGE]);

    $this->actingAs($admin)
        ->get(route('superadmin.pages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/Pages/Index'));

    $this->actingAs($admin)
        ->get(route('superadmin.sections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/Sections/Index'));

    $this->actingAs($admin)
        ->getJson(route('superadmin.assets.list'))
        ->assertOk()
        ->assertJsonPath('assets.0.is_image', true);
});

it('exposes platform stock images in the shared assets module', function () {
    $admin = platformSectionAdmin([PlatformPermissions::WELCOME_MANAGE]);

    $response = $this->actingAs($admin)
        ->getJson(route('superadmin.assets.list'))
        ->assertOk()
        ->json('assets');

    expect($response)->toBeArray()
        ->and(collect($response)->contains(fn (array $asset) => ($asset['url'] ?? null) === '/images/landing/stock/field-checklist.jpg'))
        ->and(collect($response)->contains(fn (array $asset) => ($asset['url'] ?? null) === '/images/mega-menu/operations-suite.svg'))
        ->and(collect($response)->contains(fn (array $asset) => ($asset['is_system'] ?? false) === true))->toBeTrue();

    $welcomeAssets = $this->actingAs($admin)
        ->getJson(route('superadmin.assets.list', ['tag' => 'welcome']))
        ->assertOk()
        ->json('assets');

    expect($welcomeAssets)->toBeArray()
        ->and(collect($welcomeAssets)->contains(fn (array $asset) => ($asset['url'] ?? null) === '/images/landing/stock/field-checklist.jpg'))
        ->and(collect($welcomeAssets)->every(fn (array $asset) => in_array('welcome', $asset['tags'] ?? [], true)))->toBeTrue();
});

it('keeps an empty sections payload empty when saving a page from the admin', function () {
    $admin = platformSectionAdmin([PlatformPermissions::PAGES_MANAGE]);

    $page = PlatformPage::query()->create([
        'slug' => 'admin-empty-sections',
        'title' => 'Admin empty sections',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'Admin empty sections',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'section-1',
                            'enabled' => true,
                            'layout' => 'split',
                            'title' => 'Original section',
                            'body' => '<p>Original body</p>',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->put(route('superadmin.pages.update', $page), [
            'slug' => 'admin-empty-sections',
            'title' => 'Admin empty sections',
            'is_active' => true,
            'locale' => 'fr',
            'content' => [
                'page_title' => 'Admin empty sections',
                'page_subtitle' => '',
                'header' => [
                    'background_type' => 'none',
                    'background_color' => '',
                    'background_image_url' => '',
                    'background_image_alt' => '',
                    'alignment' => 'center',
                ],
                'sections_present' => true,
            ],
            'theme' => [],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Page updated.');

    expect(data_get($page->fresh()->content, 'locales.fr.sections'))->toBe([]);
});

it('does not inflate admin page sections from shared media stored in another locale', function () {
    $admin = platformSectionAdmin([PlatformPermissions::PAGES_MANAGE]);

    $page = PlatformPage::query()->create([
        'slug' => 'shared-media-section-count',
        'title' => 'Shared media section count',
        'is_active' => true,
        'content' => [
            'locales' => [],
        ],
    ]);

    $service = app(\App\Services\PlatformPageContentService::class);

    $frPayload = $service->defaultContent('fr', $page);
    $frPayload['sections'] = [
        [
            'id' => 'fr-section-1',
            'enabled' => true,
            'layout' => 'split',
            'title' => 'Hero FR',
            'body' => '<p>FR body</p>',
            'image_url' => 'https://example.com/fr-hero.jpg',
            'image_alt' => 'FR hero image',
        ],
    ];

    $enPayload = $service->defaultContent('en', $page);
    $enPayload['sections'] = [
        [
            'id' => 'en-section-1',
            'enabled' => true,
            'layout' => 'split',
            'title' => 'Hero EN',
            'body' => '<p>EN body</p>',
            'image_url' => 'https://example.com/en-hero.jpg',
            'image_alt' => 'EN hero image',
        ],
        [
            'id' => 'en-section-2',
            'enabled' => true,
            'layout' => 'split',
            'title' => 'Extra EN',
            'body' => '<p>Extra EN body</p>',
            'image_url' => 'https://example.com/en-extra.jpg',
            'image_alt' => 'EN extra image',
        ],
    ];

    $service->updateLocale($page, 'fr', $frPayload, $admin->id);
    $service->updateLocale($page->fresh(), 'en', $enPayload, $admin->id);

    $this->actingAs($admin)
        ->get(route('superadmin.pages.edit', $page))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('SuperAdmin/Pages/Edit')
            ->where('content.fr.sections.0.id', 'en-section-1')
            ->where('content.fr.sections.1.id', 'en-section-2')
            ->missing('content.fr.sections.2')
        );
});

it('keeps welcome page sections synchronized across locales when opening the admin editor', function () {
    $admin = platformSectionAdmin([PlatformPermissions::WELCOME_MANAGE]);
    app()->setLocale('fr');

    $page = PlatformPage::query()->create([
        'slug' => 'welcome',
        'title' => 'Welcome',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'Accueil',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'welcome-hero',
                            'enabled' => true,
                            'layout' => 'split',
                            'title' => 'Hero FR',
                            'body' => '<p>Hero FR</p>',
                        ],
                        [
                            'id' => 'welcome-cta',
                            'enabled' => true,
                            'layout' => 'cta',
                            'title' => 'CTA FR',
                            'body' => '<p>CTA FR</p>',
                        ],
                    ],
                ],
                'en' => [
                    'page_title' => 'Welcome',
                    'page_subtitle' => '',
                    'header' => [
                        'background_type' => 'none',
                        'background_color' => '',
                        'background_image_url' => '',
                        'background_image_alt' => '',
                        'alignment' => 'center',
                    ],
                    'sections' => [
                        [
                            'id' => 'welcome-hero',
                            'enabled' => true,
                            'layout' => 'split',
                            'title' => 'Hero EN',
                            'body' => '<p>Hero EN</p>',
                        ],
                        [
                            'id' => 'welcome-bonus',
                            'enabled' => true,
                            'layout' => 'stack',
                            'title' => 'Bonus EN',
                            'body' => '<p>Bonus EN</p>',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('superadmin.pages.edit', $page))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('SuperAdmin/Pages/Edit')
            ->where('default_locale', 'en')
            ->where('content.en.sections.0.id', 'welcome-hero')
            ->where('content.en.sections.1.id', 'welcome-bonus')
            ->missing('content.en.sections.2')
            ->where('content.fr.sections.0.id', 'welcome-hero')
            ->where('content.fr.sections.0.title', 'Hero FR')
            ->where('content.fr.sections.1.id', 'welcome-bonus')
            ->where('content.fr.sections.1.title', 'Bonus EN')
            ->missing('content.fr.sections.2')
            ->where('content.en.sections.0.title', 'Hero EN')
            ->where('content.en.sections.1.title', 'Bonus EN')
        );
});
