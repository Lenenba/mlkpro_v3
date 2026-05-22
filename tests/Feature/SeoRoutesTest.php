<?php

use App\Models\PlatformPage;

test('malformed empty public query strings redirect to the clean URL', function () {
    $this->get('/?%24=')
        ->assertStatus(301)
        ->assertRedirect('/');
});

test('meaningful public query parameters are preserved', function () {
    $this->get('/pricing?currency=USD')
        ->assertOk();
});

test('sitemap exposes only canonical public URLs', function () {
    config(['app.url' => 'https://malikiapro.com']);

    PlatformPage::query()->create([
        'slug' => 'sales-crm',
        'title' => 'Sales CRM',
        'is_active' => true,
        'content' => [],
    ]);

    PlatformPage::query()->create([
        'slug' => 'draft-page',
        'title' => 'Draft page',
        'is_active' => false,
        'content' => [],
    ]);

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>https://malikiapro.com/</loc>', false)
        ->assertSee('<loc>https://malikiapro.com/pricing</loc>', false)
        ->assertSee('<loc>https://malikiapro.com/pages/sales-crm</loc>', false)
        ->assertDontSee('draft-page');
});
