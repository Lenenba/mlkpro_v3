<?php

use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Services\Social\SocialPostRevisionSnapshotService;
use Carbon\CarbonImmutable;

uses(Tests\TestCase::class);

it('returns the same canonical snapshot for equivalent JSON while preserving unicode and list order', function () {
    $scheduledFor = CarbonImmutable::parse('2026-09-15 18:30:00', 'UTC');
    $ruleUpdatedAt = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
    $rule = (new SocialAutomationRule)->forceFill([
        'id' => 7,
        'user_id' => 91,
        'approval_mode' => SocialAutomationRule::APPROVAL_AUTO_PUBLISH,
        'updated_at' => $ruleUpdatedAt,
    ]);
    $firstPost = new SocialPost([
        'user_id' => 91,
        'source_type' => 'promotion',
        'source_id' => 42,
        'social_automation_rule_id' => 7,
        'content_payload' => [
            'text' => 'L’été à Montréal — déjà prêt.',
            'metadata' => [
                'zeta' => 'fin',
                'audience' => [
                    'segment' => 'VIP',
                    'region' => 'Québec',
                ],
            ],
            'hashtags' => ['#Été', '#Beauté'],
        ],
        'media_payload' => [
            [
                'url' => 'https://cdn.example.com/z-héros.jpg',
                'type' => 'image',
                'metadata' => [
                    'width' => 1080,
                    'alt' => 'Soin éclat',
                    'height' => 1350,
                ],
            ],
            [
                'url' => 'https://cdn.example.com/a-détail.jpg',
                'type' => 'image',
            ],
        ],
        'link_url' => 'https://example.com/offres/été',
        'scheduled_for' => $scheduledFor,
        'metadata' => [
            'link_cta_label' => 'Réserver maintenant',
            'source' => [
                'label' => 'Forfait éclat',
                'private_note' => 'must-not-be-snapshotted',
            ],
            'automation' => [
                'selected_source_label' => 'Fallback non utilisé',
                'provider_secret' => 'must-not-be-snapshotted',
            ],
        ],
    ]);
    $equivalentPost = new SocialPost([
        'user_id' => 91,
        'social_automation_rule_id' => 7,
        'source_id' => 42,
        'source_type' => 'promotion',
        'content_payload' => [
            'hashtags' => ['#Été', '#Beauté'],
            'metadata' => [
                'audience' => [
                    'region' => 'Québec',
                    'segment' => 'VIP',
                ],
                'zeta' => 'fin',
            ],
            'text' => 'L’été à Montréal — déjà prêt.',
        ],
        'media_payload' => [
            [
                'metadata' => [
                    'alt' => 'Soin éclat',
                    'height' => 1350,
                    'width' => 1080,
                ],
                'type' => 'image',
                'url' => 'https://cdn.example.com/z-héros.jpg',
            ],
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/a-détail.jpg',
            ],
        ],
        'link_url' => 'https://example.com/offres/été',
        'scheduled_for' => $scheduledFor,
        'metadata' => [
            'automation' => [
                'provider_secret' => 'must-not-be-snapshotted',
                'selected_source_label' => 'Fallback non utilisé',
            ],
            'source' => [
                'private_note' => 'must-not-be-snapshotted',
                'label' => 'Forfait éclat',
            ],
            'link_cta_label' => 'Réserver maintenant',
        ],
    ]);
    $firstPost->setRelation('automationRule', $rule);
    $equivalentPost->setRelation('automationRule', $rule);
    $service = new SocialPostRevisionSnapshotService;

    $snapshot = $service->forPost($firstPost, 'America/Toronto');
    $equivalentSnapshot = $service->forPost($equivalentPost, 'America/Toronto');

    $snapshotKeys = array_keys($snapshot);
    sort($snapshotKeys, SORT_STRING);

    expect($snapshotKeys)->toBe([
        'base_content',
        'media_snapshot',
        'payload_hash',
        'scheduled_for',
        'scheduled_local_time',
        'scheduled_timezone',
        'source_snapshot',
    ]);
    expect($snapshot)->toBe($equivalentSnapshot);
    expect($snapshot['base_content'])->toBe([
        'content_payload' => [
            'hashtags' => ['#Été', '#Beauté'],
            'metadata' => [
                'audience' => [
                    'region' => 'Québec',
                    'segment' => 'VIP',
                ],
                'zeta' => 'fin',
            ],
            'text' => 'L’été à Montréal — déjà prêt.',
        ],
        'link_cta_label' => 'Réserver maintenant',
        'link_url' => 'https://example.com/offres/été',
    ]);
    $policyFingerprint = data_get($snapshot, 'source_snapshot.autopilot_policy.policy_fingerprint');
    expect($policyFingerprint)->toBeString()->toMatch('/\A[0-9a-f]{64}\z/');
    expect($snapshot['source_snapshot'])->toBe([
        'autopilot_policy' => [
            'approval_mode' => SocialAutomationRule::APPROVAL_AUTO_PUBLISH,
            'policy_fingerprint' => $policyFingerprint,
            'rule_id' => 7,
            'rule_updated_at' => '2026-08-20T12:00:00Z',
        ],
        'source_id' => 42,
        'source_label' => 'Forfait éclat',
        'source_type' => 'promotion',
    ]);
    expect($snapshot['media_snapshot'])->toBe([
        'items' => [
            [
                'metadata' => [
                    'alt' => 'Soin éclat',
                    'height' => 1350,
                    'width' => 1080,
                ],
                'type' => 'image',
                'url' => 'https://cdn.example.com/z-héros.jpg',
            ],
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/a-détail.jpg',
            ],
        ],
        'schema_version' => 1,
    ]);
    expect($snapshot['scheduled_for'])->toBe('2026-09-15T18:30:00Z');
    expect($snapshot['scheduled_timezone'])->toBe('America/Toronto');
    expect($snapshot['scheduled_local_time'])->toBe('2026-09-15 14:30:00');
    expect($snapshot['payload_hash'])->toMatch('/\A[0-9a-f]{64}\z/');
});

it('changes the payload hash when any revision input changes', function () {
    $scheduledFor = CarbonImmutable::parse('2026-09-15 18:30:00', 'UTC');
    $ruleUpdatedAt = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
    $attributes = [
        'user_id' => 91,
        'source_type' => 'promotion',
        'source_id' => 42,
        'social_automation_rule_id' => 7,
        'content_payload' => [
            'text' => 'Contenu approuvé',
            'hashtags' => ['#Premier', '#Second'],
        ],
        'media_payload' => [
            ['type' => 'image', 'url' => 'https://cdn.example.com/z-first.jpg'],
            ['type' => 'image', 'url' => 'https://cdn.example.com/a-second.jpg'],
        ],
        'link_url' => 'https://example.com/offres/originale',
        'scheduled_for' => $scheduledFor,
        'metadata' => [
            'link_cta_label' => 'Réserver',
            'source' => ['label' => 'Forfait original'],
        ],
    ];
    $variants = [
        'content' => [
            'attributes' => ['content_payload' => ['text' => 'Contenu modifié', 'hashtags' => ['#Premier', '#Second']]],
            'timezone' => 'America/Toronto',
        ],
        'media value' => [
            'attributes' => ['media_payload' => [
                ['type' => 'image', 'url' => 'https://cdn.example.com/z-changed.jpg'],
                ['type' => 'image', 'url' => 'https://cdn.example.com/a-second.jpg'],
            ]],
            'timezone' => 'America/Toronto',
        ],
        'media list order' => [
            'attributes' => ['media_payload' => [
                ['type' => 'image', 'url' => 'https://cdn.example.com/a-second.jpg'],
                ['type' => 'image', 'url' => 'https://cdn.example.com/z-first.jpg'],
            ]],
            'timezone' => 'America/Toronto',
        ],
        'link' => [
            'attributes' => ['link_url' => 'https://example.com/offres/modifiee'],
            'timezone' => 'America/Toronto',
        ],
        'CTA' => [
            'attributes' => ['metadata' => [
                'link_cta_label' => 'Acheter',
                'source' => ['label' => 'Forfait original'],
            ]],
            'timezone' => 'America/Toronto',
        ],
        'source label' => [
            'attributes' => ['metadata' => [
                'link_cta_label' => 'Réserver',
                'source' => ['label' => 'Forfait modifié'],
            ]],
            'timezone' => 'America/Toronto',
        ],
        'source type' => [
            'attributes' => ['source_type' => 'campaign'],
            'timezone' => 'America/Toronto',
        ],
        'source id' => [
            'attributes' => ['source_id' => 43],
            'timezone' => 'America/Toronto',
        ],
        'automation rule' => [
            'attributes' => ['social_automation_rule_id' => 8],
            'timezone' => 'America/Toronto',
            'rule' => [
                'id' => 8,
            ],
        ],
        'automation approval mode' => [
            'attributes' => [],
            'timezone' => 'America/Toronto',
            'rule' => [
                'approval_mode' => SocialAutomationRule::APPROVAL_AUTO_PUBLISH,
            ],
        ],
        'automation rule version' => [
            'attributes' => [],
            'timezone' => 'America/Toronto',
            'rule' => [
                'updated_at' => $ruleUpdatedAt->addMinute(),
            ],
        ],
        'automation policy fingerprint' => [
            'attributes' => [],
            'timezone' => 'America/Toronto',
            'rule' => [
                'metadata' => [
                    'generation_settings' => ['tone' => 'premium'],
                ],
            ],
        ],
        'scheduled instant' => [
            'attributes' => ['scheduled_for' => $scheduledFor->addMinute()],
            'timezone' => 'America/Toronto',
        ],
        'scheduled timezone' => [
            'attributes' => [],
            'timezone' => 'Europe/Paris',
        ],
    ];
    $service = new SocialPostRevisionSnapshotService;
    $postFor = function (array $postAttributes, array $ruleOverrides = []) use ($ruleUpdatedAt): SocialPost {
        $post = new SocialPost($postAttributes);
        $rule = (new SocialAutomationRule)->forceFill(array_replace([
            'id' => (int) $post->social_automation_rule_id,
            'user_id' => (int) $post->user_id,
            'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
            'updated_at' => $ruleUpdatedAt,
        ], $ruleOverrides));
        $post->setRelation('automationRule', $rule);

        return $post;
    };
    $baselineHash = $service->forPost($postFor($attributes), 'America/Toronto')['payload_hash'];
    $changedHashes = [];

    foreach ($variants as $name => $variant) {
        $post = $postFor(
            array_replace($attributes, $variant['attributes']),
            $variant['rule'] ?? [],
        );
        $changedHashes[$name] = $service->forPost($post, $variant['timezone'])['payload_hash'];
    }

    expect($changedHashes)->not->toContain($baselineHash);
    expect(array_unique($changedHashes))->toHaveCount(count($changedHashes));
});
