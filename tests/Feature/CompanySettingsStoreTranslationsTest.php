<?php

use App\Models\User;

test('company settings update stores spanish store hero translations', function () {
    $owner = User::factory()->create([
        'company_name' => 'Casa Norte',
        'company_type' => 'products',
        'company_store_settings' => [
            'header_color' => '#0f172a',
            'invoice_template_key' => 'modern',
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
                'invoice_template_key' => 'clean_professional',
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
        ->and($settings['invoice_template_key'])->toBe('clean_professional')
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

test('company settings update stores the minimal corporate invoice template key', function () {
    $owner = User::factory()->create([
        'company_name' => 'Casa Norte',
        'company_type' => 'products',
        'company_store_settings' => [
            'invoice_template_key' => 'modern',
        ],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('settings.company.edit'))
        ->put(route('settings.company.update'), [
            'company_name' => 'Casa Norte',
            'company_type' => 'products',
            'company_store_settings' => [
                'invoice_template_key' => 'minimal_corporate',
            ],
        ]);

    $response
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHasNoErrors();

    expect($owner->fresh()->company_store_settings['invoice_template_key'])->toBe('minimal_corporate');
});

test('company settings update stores company alert channels and preserves reservation settings', function () {
    $owner = User::factory()->create([
        'company_name' => 'Casa Norte',
        'company_type' => 'services',
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => true,
                'in_app' => true,
                'sms' => false,
                'notify_on_reminder' => true,
                'reminder_hours' => [24],
            ],
        ],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->from(route('settings.company.edit'))
        ->put(route('settings.company.update'), [
            'company_name' => 'Casa Norte',
            'company_type' => 'services',
            'company_notification_settings' => [
                'preferred_channel' => 'whatsapp',
                'alerts' => [
                    'task_day' => [
                        'email' => true,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                    'task_updates' => [
                        'email' => false,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                    'crm' => [
                        'email' => true,
                        'sms' => false,
                        'whatsapp' => true,
                    ],
                    'expenses' => [
                        'email' => true,
                        'sms' => true,
                        'whatsapp' => false,
                    ],
                    'reservations' => [
                        'email' => false,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                ],
                'task_day' => [
                    'email' => true,
                    'sms' => true,
                    'whatsapp' => true,
                ],
                'task_updates' => [
                    'email' => false,
                    'sms' => true,
                    'whatsapp' => true,
                ],
                'security' => [
                    'two_factor_sms' => true,
                ],
            ],
        ]);

    $response
        ->assertRedirect(route('settings.company.edit'))
        ->assertSessionHasNoErrors();

    $settings = $owner->fresh()->company_notification_settings;

    expect($settings['preferred_channel'])->toBe('whatsapp')
        ->and($settings['alerts']['task_day'])->toBe([
            'email' => true,
            'sms' => true,
            'whatsapp' => true,
        ])
        ->and($settings['task_day'])->toBe($settings['alerts']['task_day'])
        ->and($settings['alerts']['task_updates'])->toBe([
            'email' => false,
            'sms' => true,
            'whatsapp' => true,
        ])
        ->and($settings['task_updates'])->toBe($settings['alerts']['task_updates'])
        ->and($settings['alerts']['crm']['whatsapp'])->toBeTrue()
        ->and($settings['alerts']['expenses']['sms'])->toBeTrue()
        ->and($settings['alerts']['orders']['email'])->toBeTrue()
        ->and($settings['security']['two_factor_sms'])->toBeTrue()
        ->and($settings['reservations']['enabled'])->toBeTrue()
        ->and($settings['reservations']['email'])->toBeFalse()
        ->and($settings['reservations']['sms'])->toBeTrue()
        ->and($settings['reservations']['whatsapp'])->toBeTrue()
        ->and($settings['reservations']['reminder_hours'])->toBe([24]);
});
