<?php

use App\Models\User;

test('company settings update stores spanish store hero translations', function () {
    $owner = User::factory()->create([
        'company_name' => 'Casa Norte',
        'company_type' => 'products',
        'company_store_settings' => [
            'header_color' => '#0f172a',
            'hero_images' => ['https://example.com/existing-hero.jpg'],
            'hero_copy' => [
                'fr' => '<p>Bonjour {company}</p>',
                'en' => '<p>Hello {company}</p>',
            ],
            'hero_captions' => [
                'fr' => ['<p>Slide FR</p>'],
                'en' => ['<p>Slide EN</p>'],
            ],
        ],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('settings.company.edit'))
        ->put(route('settings.company.update'), [
            'company_name' => 'Casa Norte',
            'company_type' => 'products',
            'company_store_settings' => [
                'header_color' => '#123456',
                'hero_images' => [
                    'https://example.com/hero-1.jpg',
                    'https://example.com/hero-2.jpg',
                ],
                'hero_copy' => [
                    'fr' => '<p>Bonjour {company}</p>',
                    'es' => '<p>Hola {company}</p>',
                    'en' => '<p>Hello {company}</p>',
                ],
                'hero_captions' => [
                    'fr' => ['<p>Slide FR 1</p>', ''],
                    'es' => ['<p>Slide ES 1</p>', '<p>Slide ES 2</p>'],
                    'en' => ['<p>Slide EN 1</p>'],
                ],
            ],
        ]);

    $response
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Company settings updated.');

    $settings = $owner->fresh()->company_store_settings;
    $heroCopy = $settings['hero_copy'];
    ksort($heroCopy);

    expect($settings['header_color'])->toBe('#123456')
        ->and($settings['hero_images'])->toBe([
            'https://example.com/hero-1.jpg',
            'https://example.com/hero-2.jpg',
        ])
        ->and($heroCopy)->toBe([
            'en' => '<p>Hello {company}</p>',
            'es' => '<p>Hola {company}</p>',
            'fr' => '<p>Bonjour {company}</p>',
        ])
        ->and($settings['hero_captions']['fr'])->toBe([
            '<p>Slide FR 1</p>',
            null,
        ])
        ->and($settings['hero_captions']['es'])->toBe([
            '<p>Slide ES 1</p>',
            '<p>Slide ES 2</p>',
        ])
        ->and($settings['hero_captions']['en'])->toBe([
            '<p>Slide EN 1</p>',
            null,
        ]);
});
