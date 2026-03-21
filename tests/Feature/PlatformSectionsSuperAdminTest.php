<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\PlatformAdmin;
use App\Models\PlatformSection;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
