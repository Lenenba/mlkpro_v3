<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Modules\AiAssistant\Services\AiIntentDetector;

function createAiIntentService(User $tenant, string $name = 'Reservation QA'): Product
{
    $category = ProductCategory::factory()->create([
        'user_id' => $tenant->id,
    ]);

    return Product::query()->create([
        'name' => $name,
        'description' => 'Service test',
        'category_id' => $category->id,
        'user_id' => $tenant->id,
        'stock' => 0,
        'price' => 5000,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
        'unit' => 'service',
    ]);
}

test('ai intent detector detects reservation intent in french and english', function () {
    $detector = app(AiIntentDetector::class);

    $french = $detector->detect('Bonjour, je veux reserver demain.');
    $english = $detector->detect('Hi, can I book an appointment tomorrow?');
    $typoFrench = $detector->detect('je mappel justin et je veu faire une reservavation');

    expect($french->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($french->language)->toBe(AiAssistantSetting::LANGUAGE_FR)
        ->and($french->confidence)->toBeGreaterThan(0.8)
        ->and($english->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($english->language)->toBe(AiAssistantSetting::LANGUAGE_EN)
        ->and($english->confidence)->toBeGreaterThan(0.8)
        ->and($typoFrench->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($typoFrench->language)->toBe(AiAssistantSetting::LANGUAGE_FR);
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

test('ai assistant keeps an active reservation flow when the next answer is a plain name', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'America/Toronto',
    ]);
    createAiIntentService($tenant);
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
        'metadata' => [],
    ]);
    $assistant = app(AiAssistantService::class);

    $firstResponse = $assistant->handleUserMessage($conversation, 'je veux une reservation');
    $conversation->refresh();

    expect($firstResponse->message)->toContain('Quel est votre nom complet?')
        ->and($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION);

    $conversation->update(['status' => AiConversation::STATUS_WAITING_HUMAN]);

    $secondResponse = $assistant->handleUserMessage($conversation, 'jules roger');
    $conversation->refresh();

    expect($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($conversation->status)->toBe(AiConversation::STATUS_OPEN)
        ->and(data_get($conversation->metadata, 'reservation_draft.contact_name'))->toBe('jules roger')
        ->and($secondResponse->message)->toContain('Quel numero de telephone pouvons-nous utiliser?')
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('ai assistant extracts a name from a typo-heavy french reservation request', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'America/Toronto',
    ]);
    createAiIntentService($tenant);
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
        'metadata' => [],
    ]);

    $response = app(AiAssistantService::class)
        ->handleUserMessage($conversation, 'je mappel justin et je veu faire une reservavation');

    $conversation->refresh();

    expect($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($conversation->detected_language)->toBe(AiAssistantSetting::LANGUAGE_FR)
        ->and(data_get($conversation->metadata, 'reservation_draft.contact_name'))->toBe('justin')
        ->and($response->message)->toContain('Quel numero de telephone pouvons-nous utiliser?')
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('ai assistant recovers a missed reservation intent from recent conversation history', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'America/Toronto',
    ]);
    createAiIntentService($tenant);
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
        'detected_language' => AiAssistantSetting::LANGUAGE_FR,
        'status' => AiConversation::STATUS_WAITING_HUMAN,
        'intent' => AiConversation::INTENT_GENERAL,
        'metadata' => [],
    ]);
    AiMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => AiMessage::SENDER_USER,
        'content' => 'je mappel justin et je veu faire une reservavation',
    ]);

    $response = app(AiAssistantService::class)->handleUserMessage($conversation, 'd');

    $conversation->refresh();

    expect($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($conversation->status)->toBe(AiConversation::STATUS_OPEN)
        ->and(data_get($conversation->metadata, 'reservation_draft.contact_name'))->toBe('justin')
        ->and($response->message)->toContain('Quel numero de telephone pouvons-nous utiliser?')
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});
