<?php

use App\Models\PlatformSetting;
use App\Models\PlatformPage;
use App\Models\Role;
use App\Models\User;
use App\Services\MegaMenus\MegaMenuRenderer;
use Database\Seeders\MegaMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('seeds public product pages and links the showcase menu to them', function () {
    $this->seed(MegaMenuSeeder::class);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $showcaseItem = $menu['items'][0]['columns'][0]['blocks'][0]['payload']['items'][0] ?? null;

    expect($showcaseItem)->not->toBeNull();
    expect($showcaseItem['href'])->toBe('/pages/sales-crm');

    $this->get(route('public.pages.show', ['slug' => 'sales-crm']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'sales-crm')
            ->where('content.page_title', 'Sales & CRM')
            ->has('content.sections', 3)
        );
});

it('seeds industries and contact us in the public header', function () {
    $ownerRole = Role::query()->firstOrCreate(['name' => 'owner'], ['description' => 'Owner']);
    User::query()->create([
        'name' => 'Owner Services',
        'email' => 'owner.services@example.com',
        'role_id' => $ownerRole->id,
        'password' => 'password',
        'company_features' => ['requests' => true],
    ]);

    $this->seed(MegaMenuSeeder::class);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $labels = collect($menu['items'])->pluck('label')->values()->all();
    $industries = $menu['items'][3] ?? null;
    $contact = $menu['items'][4] ?? null;

    expect($labels)->toBe([
        'Produits & Services',
        'Solutions',
        'Tarifs',
        'Industries',
        'Contact us',
    ]);

    expect($industries)->not->toBeNull();
    expect($industries['panel_type'])->toBe('classic');
    expect($industries['children'])->toHaveCount(6);
    expect($contact)->not->toBeNull();
    expect($contact['resolved_href'])->toBe('/pages/contact-us');

    $contactPage = PlatformPage::query()
        ->where('slug', 'contact-us')
        ->firstOrFail();
    $embedUrl = $contactPage->content['locales']['fr']['sections'][0]['embed_url'] ?? '';

    $this->get(route('public.pages.show', ['slug' => 'contact-us']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'contact-us')
            ->where('content.sections.0.embed_height', 820)
            ->where('content.sections.0.embed_title', fn ($value) => in_array($value, ['Formulaire de demande commerciale', 'Commercial inquiry form'], true))
            ->where('content.sections.0.embed_url', fn ($value) => is_string($value) && str_contains($value, '/public/requests/') && str_contains($value, 'embed=1'))
        );

    expect($embedUrl)->not->toBeEmpty();

    $this->get($embedUrl)->assertOk();
});

it('seeds public solution pages and links the solutions menu to them', function () {
    $this->seed(MegaMenuSeeder::class);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $solutions = $menu['items'][1] ?? null;
    $firstSolution = $solutions['children'][0] ?? null;

    expect($solutions)->not->toBeNull();
    expect($solutions['label'])->toBe('Solutions');
    expect($solutions['children'])->toHaveCount(6);
    expect($firstSolution)->not->toBeNull();
    expect($firstSolution['resolved_href'])->toBe('/pages/solution-field-services');

    $this->get(route('public.pages.show', ['slug' => 'solution-field-services']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'solution-field-services')
            ->where('content.page_title', fn ($value) => in_array($value, ['Services terrain', 'Field services'], true))
            ->has('content.sections', 3)
        );
});

it('uses the configurable contact form url for the contact us header item', function () {
    $this->seed(MegaMenuSeeder::class);

    PlatformSetting::setValue('public_navigation', [
        'contact_form_url' => 'https://example.com/forms/contact',
    ]);

    $menu = app(MegaMenuRenderer::class)->resolveBySlug('main-header-menu');
    $contact = collect($menu['items'])->firstWhere('label', 'Contact us');

    expect($contact)->not->toBeNull();
    expect($contact['resolved_href'])->toBe('https://example.com/forms/contact');
});

it('resolves header presentation settings for public pages', function () {
    $page = PlatformPage::query()->create([
        'slug' => 'header-showcase',
        'title' => 'Header showcase',
        'is_active' => true,
        'content' => [
            'locales' => [
                'fr' => [
                    'page_title' => 'Header dynamique',
                    'page_subtitle' => '<p>Contenu de test</p>',
                    'header' => [
                        'background_type' => 'image',
                        'background_image_url' => '/images/mega-menu/commerce-dashboard.jpg',
                        'background_image_alt' => 'Tableau de bord commerce',
                        'alignment' => 'right',
                    ],
                    'sections' => [],
                ],
                'en' => [
                    'page_title' => 'Dynamic header',
                    'page_subtitle' => '<p>Test content</p>',
                    'header' => [
                        'background_type' => 'image',
                        'background_image_url' => '/images/mega-menu/commerce-dashboard.jpg',
                        'background_image_alt' => 'Commerce dashboard',
                        'alignment' => 'right',
                    ],
                    'sections' => [],
                ],
            ],
        ],
    ]);

    $this->get(route('public.pages.show', ['slug' => $page->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Public/Page')
            ->where('content.header.background_type', 'image')
            ->where('content.header.background_image_url', '/images/mega-menu/commerce-dashboard.jpg')
            ->where('content.header.alignment', 'right')
        );
});
