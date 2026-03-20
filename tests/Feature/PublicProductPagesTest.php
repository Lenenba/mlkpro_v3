<?php

use App\Models\PlatformSetting;
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

    $this->get(route('public.pages.show', ['slug' => 'contact-us']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Page')
            ->where('page.slug', 'contact-us')
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
