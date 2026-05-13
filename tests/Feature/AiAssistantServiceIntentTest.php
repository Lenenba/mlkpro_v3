<?php

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Modules\AiAssistant\Services\AiIntentDetector;

test('ai intent detector detects reservation intent in french and english', function () {
    $detector = app(AiIntentDetector::class);

    $french = $detector->detect('Bonjour, je veux reserver demain.');
    $english = $detector->detect('Hi, can I book an appointment tomorrow?');

    expect($french->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($french->language)->toBe(AiAssistantSetting::LANGUAGE_FR)
        ->and($french->confidence)->toBeGreaterThan(0.8)
        ->and($english->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($english->language)->toBe(AiAssistantSetting::LANGUAGE_EN)
        ->and($english->confidence)->toBeGreaterThan(0.8);
});

test('ai assistant service answers reservation flow in detected english', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'America/Toronto',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'default_language' => AiAssistantSetting::LANGUAGE_FR,
        'supported_languages' => [
            AiAssistantSetting::LANGUAGE_FR,
            AiAssistantSetting::LANGUAGE_EN,
        ],
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'detected_language' => null,
    ]);

    $response = app(AiAssistantService::class)
        ->handleUserMessage($conversation, 'Hi, can I book an appointment tomorrow?');

    $conversation->refresh();

    expect($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($conversation->detected_language)->toBe(AiAssistantSetting::LANGUAGE_EN)
        ->and((float) $conversation->confidence_score)->toBeGreaterThan(0.8)
        ->and($response->message)->toContain('Which service would you like to book?');
});

test('ai assistant service requests human review when confidence is low', function () {
    $tenant = User::factory()->create();
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'fallback_message' => 'The team will review this request.',
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = app(AiAssistantService::class)
        ->handleUserMessage($conversation, 'I have a complex question about a custom policy.');

    $conversation->refresh();

    expect($conversation->intent)->toBe(AiConversation::INTENT_GENERAL)
        ->and($conversation->status)->toBe(AiConversation::STATUS_WAITING_HUMAN)
        ->and((float) $conversation->confidence_score)->toBeLessThan(0.5)
        ->and($response->status)->toBe(AiConversation::STATUS_WAITING_HUMAN)
        ->and($response->message)->toBe('The team will review this request.');

    $this->assertDatabaseHas('ai_actions', [
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'action_type' => AiAction::TYPE_REQUEST_HUMAN_REVIEW,
        'status' => AiAction::STATUS_EXECUTED,
    ]);
});
