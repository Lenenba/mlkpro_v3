<?php

use App\Models\PlatformSection;
use App\Models\User;
use App\Services\PlatformSectionContentService;

it('preserves utf 8 characters in html based section copy', function () {
    $user = User::factory()->create();
    $service = app(PlatformSectionContentService::class);

    $section = PlatformSection::query()->create([
        'name' => 'UTF-8 feature pairs',
        'type' => 'feature_pairs',
        'is_active' => true,
        'content' => ['locales' => []],
    ]);

    $service->updateLocale($section, 'fr', [
        'title' => 'Ajoutez automatisation, paiements connectés et vente plus intelligente à votre flux',
        'body' => "<p>Ces ajouts aident l'équipe à gagner du temps, à réduire l'administration et à rendre la plateforme plus utile.</p>",
        'feature_items' => [
            [
                'key' => 'reservations',
                'title' => 'Réservations et flux client',
                'desc' => '<p>Offrez une expérience de réservation plus simple tout en gardant la maîtrise sur les arrivées, les disponibilités et la file.</p>',
            ],
        ],
        'secondary_enabled' => true,
        'secondary_title' => 'Conçu pour aller plus vite',
        'secondary_body' => "<p>Accélérez la création de devis, de jobs et d'actions administratives avec moins de répétition manuelle.</p>",
        'secondary_feature_items' => [
            [
                'key' => 'assistant',
                'title' => 'Assistant IA',
                'desc' => "<p>Accélérez la création de devis, de jobs et d'actions administratives avec moins de répétition manuelle.</p>",
            ],
        ],
    ], $user->id);

    $resolved = $service->resolveForLocale($section->fresh(), 'fr');

    expect($resolved['body'])->toContain("l'équipe", 'réduire', 'plateforme')
        ->and($resolved['feature_items'][0]['title'])->toBe('Réservations et flux client')
        ->and($resolved['feature_items'][0]['desc'])->toContain('expérience', 'réservation', 'maîtrise', 'arrivées', 'disponibilités')
        ->and($resolved['secondary_title'])->toBe('Conçu pour aller plus vite')
        ->and($resolved['secondary_body'])->toContain('Accélérez', "d'actions")
        ->and($resolved['secondary_feature_items'][0]['desc'])->toContain('Accélérez', 'répétition')
        ->and($resolved['feature_items'][0]['desc'])->not->toContain('Ã')
        ->and($resolved['secondary_body'])->not->toContain('Ã')
        ->and($resolved['secondary_feature_items'][0]['desc'])->not->toContain('Ã');
});
