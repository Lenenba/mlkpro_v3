<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TeamMember;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use Inertia\Testing\AssertableInertia as Assert;

test('public ai assistant page renders the chat shell', function () {
    $owner = User::factory()->create([
        'company_slug' => 'studio-lumiere',
        'company_name' => 'Studio Lumiere',
        'company_logo' => 'customers/customer.png',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'assistant_name' => 'Reception Lumiere',
    ]);

    $this->get(route('public.ai-assistant.page', [
        'company' => 'studio-lumiere',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/AiAssistantChat')
            ->where('company.slug', 'studio-lumiere')
            ->where('company.logo_url', null)
            ->where('company.custom_logo_url', null)
            ->where('company.has_custom_logo', false)
            ->where('assistant.name', 'Reception Lumiere')
            ->where('endpoints.create', route('public.ai-assistant.conversations.store'))
            ->where('endpoints.show', route('public.ai-assistant.conversations.show', ['conversation' => '__conversation__']))
        );
});

test('public ai assistant can create a conversation with a greeting', function () {
    $owner = User::factory()->create([
        'company_slug' => 'studio-lumiere',
        'company_name' => 'Studio Lumiere',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'assistant_name' => 'Reception Lumiere',
        'greeting_message' => 'Bonjour, comment puis-je vous aider?',
    ]);

    $response = $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => 'studio-lumiere',
        'channel' => AiConversation::CHANNEL_WEB_CHAT,
        'visitor_name' => 'Amina Diallo',
        'visitor_email' => 'amina@example.com',
    ])
        ->assertCreated()
        ->assertJsonPath('conversation.status', AiConversation::STATUS_OPEN)
        ->assertJsonPath('message.sender_type', AiMessage::SENDER_ASSISTANT)
        ->assertJsonPath('message.content', 'Bonjour, comment puis-je vous aider?');

    $uuid = (string) $response->json('conversation.uuid');
    expect($uuid)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($response->json('conversation'))->not->toHaveKey('id')
        ->and($response->json('message'))->not->toHaveKey('id');

    $this->assertDatabaseHas('ai_conversations', [
        'tenant_id' => $owner->id,
        'public_uuid' => $uuid,
        'channel' => AiConversation::CHANNEL_WEB_CHAT,
        'visitor_email' => 'amina@example.com',
    ]);
    $this->assertDatabaseHas('ai_messages', [
        'sender_type' => AiMessage::SENDER_ASSISTANT,
        'content' => 'Bonjour, comment puis-je vous aider?',
    ]);
});

test('public reservation chat uses booking context instead of falling back to human review', function () {
    $owner = User::factory()->create([
        'company_slug' => 'service-demo-co',
        'company_name' => 'Service Demo Co',
    ]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'name' => 'Cleaning',
    ]);
    $service = Product::query()->create([
        'name' => 'Deep clean package',
        'description' => 'Detailed service',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'price' => 120,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'default_language' => AiAssistantSetting::LANGUAGE_FR,
        'supported_languages' => [AiAssistantSetting::LANGUAGE_FR, AiAssistantSetting::LANGUAGE_EN],
        'fallback_message' => "Je vais transmettre votre demande a l'equipe pour verification.",
    ]);

    $createResponse = $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => 'service-demo-co',
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
        'metadata' => [
            'source' => 'public_booking_link',
            'booking_link_id' => 3,
            'booking_link_slug' => 'tester',
            'booking_link_name' => 'Test',
            'selected_service_id' => $service->id,
            'selected_service_name' => 'Browser supplied service name',
            'selected_team_member_id' => 'auto',
        ],
    ])->assertCreated()
        ->assertJsonPath('conversation.status', AiConversation::STATUS_OPEN);

    $conversation = AiConversation::query()->where('public_uuid', $createResponse->json('conversation.uuid'))->firstOrFail();

    expect($conversation->channel)->toBe(AiConversation::CHANNEL_PUBLIC_RESERVATION)
        ->and($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and((int) data_get($conversation->metadata, 'reservation_draft.service_id'))->toBe($service->id)
        ->and(data_get($conversation->metadata, 'reservation_draft.service_name'))->toBe($service->name);

    $messageResponse = $this->postJson(route('public.ai-assistant.conversations.messages.store', [
        'conversation' => $conversation->public_uuid,
    ]), [
        'message' => 'Salut je suis jules',
    ])->assertOk()
        ->assertJsonPath('conversation.status', AiConversation::STATUS_OPEN);

    $assistantReply = (string) $messageResponse->json('messages.1.content');

    expect($assistantReply)->toContain('Bonjour Jules, ravi de vous aider.')
        ->and($assistantReply)->toContain('nom de famille')
        ->and($assistantReply)->not->toContain('numéro de téléphone')
        ->and($assistantReply)->not->toContain('transmettre');

    $shortPhoneResponse = $this->postJson(route('public.ai-assistant.conversations.messages.store', [
        'conversation' => $conversation->public_uuid,
    ]), [
        'message' => '55',
    ])->assertOk();

    expect((string) $shortPhoneResponse->json('messages.1.content'))
        ->toContain('Ce numéro semble trop court');

    $conversation->refresh();

    expect($conversation->status)->toBe(AiConversation::STATUS_OPEN)
        ->and($conversation->visitor_name)->toBe('jules')
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('public ai assistant asks for service before phone when no service was chosen', function () {
    $owner = User::factory()->create([
        'company_slug' => 'atelier-demo',
        'company_name' => 'Atelier Demo',
    ]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'name' => 'Services',
    ]);
    Product::query()->create([
        'name' => 'Consultation',
        'description' => 'Service test',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'price' => 75,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'default_language' => AiAssistantSetting::LANGUAGE_FR,
        'supported_languages' => [AiAssistantSetting::LANGUAGE_FR, AiAssistantSetting::LANGUAGE_EN],
    ]);

    $createResponse = $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => 'atelier-demo',
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
        'metadata' => [
            'source' => 'public_booking_link',
            'booking_link_id' => 9,
            'booking_link_slug' => 'demo',
            'booking_link_name' => 'Demo',
        ],
    ])->assertCreated();

    $conversation = AiConversation::query()->where('public_uuid', $createResponse->json('conversation.uuid'))->firstOrFail();

    $messageResponse = $this->postJson(route('public.ai-assistant.conversations.messages.store', [
        'conversation' => $conversation->public_uuid,
    ]), [
        'message' => 'Salut je suis jules',
    ])->assertOk();

    $assistantReply = (string) $messageResponse->json('messages.1.content');

    expect($assistantReply)->toContain('Bonjour Jules, ravi de vous aider.')
        ->and($assistantReply)->toContain('Quel service souhaitez-vous réserver?')
        ->and($assistantReply)->toContain('1. Consultation')
        ->and($assistantReply)->not->toContain('téléphone');

    $conversation->refresh();

    expect($conversation->visitor_name)->toBe('jules')
        ->and(data_get($conversation->metadata, 'reservation_draft.service_id'))->toBeNull();
});

test('public ai assistant stores user and assistant messages', function () {
    $owner = User::factory()->create([
        'company_slug' => 'barber-nord',
        'company_name' => 'Barber Nord',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'default_language' => AiAssistantSetting::LANGUAGE_FR,
        'supported_languages' => [AiAssistantSetting::LANGUAGE_FR, AiAssistantSetting::LANGUAGE_EN],
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'channel' => AiConversation::CHANNEL_WEB_CHAT,
    ]);

    $response = $this->postJson(route('public.ai-assistant.conversations.messages.store', [
        'conversation' => $conversation->public_uuid,
    ]), [
        'message' => 'Je veux reserver demain.',
    ])
        ->assertOk()
        ->assertJsonPath('conversation.uuid', $conversation->public_uuid)
        ->assertJsonPath('messages.0.sender_type', AiMessage::SENDER_USER)
        ->assertJsonPath('messages.1.sender_type', AiMessage::SENDER_ASSISTANT);

    expect($response->json('messages.0'))->not->toHaveKey('id')
        ->and($response->json('messages.1'))->not->toHaveKey('id');

    $conversation->refresh();

    expect($conversation->intent)->toBe(AiConversation::INTENT_RESERVATION)
        ->and($conversation->detected_language)->toBe(AiAssistantSetting::LANGUAGE_FR)
        ->and($conversation->messages)->toHaveCount(2);
});

test('public ai assistant resumes only the matching tenant conversation with quick replies', function () {
    $owner = User::factory()->create([
        'company_slug' => 'salon-reprise',
    ]);
    $otherOwner = User::factory()->create([
        'company_slug' => 'autre-salon',
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $otherOwner->id,
        'enabled' => true,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
        'metadata' => [
            'reservation_draft' => [
                'proposed_slots' => [
                    [
                        'index' => 1,
                        'date' => '2026-09-10',
                        'time' => '09:30',
                        'team_member_name' => 'Alice Tremblay',
                    ],
                    [
                        'index' => 2,
                        'date' => '2026-09-10',
                        'time' => '10:00',
                        'team_member_name' => 'Maya Kone',
                    ],
                ],
            ],
        ],
    ]);
    AiMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => AiMessage::SENDER_USER,
        'content' => 'Je voudrais reserver.',
    ]);
    AiMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => AiMessage::SENDER_ASSISTANT,
        'content' => 'Voici deux creneaux.',
    ]);

    $this->getJson(route('public.ai-assistant.conversations.show', [
        'conversation' => $conversation->public_uuid,
        'company' => $owner->company_slug,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
    ]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('conversation.uuid', $conversation->public_uuid)
        ->assertJsonPath('messages.0.content', 'Je voudrais reserver.')
        ->assertJsonPath('messages.1.content', 'Voici deux creneaux.')
        ->assertJsonPath('quick_replies.0.message', '1')
        ->assertJsonPath('quick_replies.1.message', '2');

    $this->getJson(route('public.ai-assistant.conversations.show', [
        'conversation' => $conversation->public_uuid,
        'company' => $otherOwner->company_slug,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
    ]))->assertNotFound();

    $this->getJson(route('public.ai-assistant.conversations.show', [
        'conversation' => $conversation->public_uuid,
        'company' => $owner->company_slug,
        'channel' => AiConversation::CHANNEL_WEB_CHAT,
    ]))->assertNotFound();
});

test('public reservation chat synchronizes explicit booking choices before replying', function () {
    $owner = User::factory()->create([
        'company_slug' => 'salon-synchronise',
        'company_timezone' => 'America/Toronto',
    ]);
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'name' => 'Coiffure',
    ]);
    $firstService = Product::query()->create([
        'name' => 'Coupe classique',
        'description' => 'Premiere coupe',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'price' => 35,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
    ]);
    $secondService = Product::query()->create([
        'name' => 'Coupe premium',
        'description' => 'Deuxieme coupe',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'price' => 55,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
    ]);
    $staffUser = User::factory()->create(['name' => 'Alice Tremblay']);
    $teamMember = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $staffUser->id,
        'is_active' => true,
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'default_language' => AiAssistantSetting::LANGUAGE_FR,
        'supported_languages' => [AiAssistantSetting::LANGUAGE_FR],
    ]);

    $createResponse = $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => $owner->company_slug,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
        'metadata' => [
            'source' => 'public_booking_link',
            'booking_link_id' => 12,
            'selected_service_id' => $firstService->id,
            'selected_date' => '2026-09-08',
            'selected_time' => '09:00',
        ],
    ])->assertCreated();

    $conversation = AiConversation::query()
        ->where('public_uuid', $createResponse->json('conversation.uuid'))
        ->firstOrFail();
    $metadata = $conversation->metadata;
    data_set($metadata, 'reservation_draft.proposed_slots', [['index' => 1, 'date' => '2026-09-08', 'time' => '09:00']]);
    data_set($metadata, 'reservation_draft.selected_slot', ['index' => 1, 'date' => '2026-09-08', 'time' => '09:00']);
    data_set($metadata, 'booking_confirmation', [
        'summary_shown' => true,
        'awaiting_user_confirmation' => true,
    ]);
    $conversation->update(['metadata' => $metadata]);

    $this->postJson(route('public.ai-assistant.conversations.messages.store', [
        'conversation' => $conversation->public_uuid,
    ]), [
        'company' => $owner->company_slug,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
        'visitor_name' => 'Pierre Jean',
        'message' => 'Bonjour',
        'metadata' => [
            'source' => 'public_booking_link',
            'booking_link_id' => 12,
            'selected_service_id' => $secondService->id,
            'selected_service_name' => 'Nom fourni par le navigateur',
            'selected_date' => '2026-09-12',
            'selected_time' => '2026-09-12T14:30:00-04:00',
            'selected_team_member_id' => $teamMember->id,
        ],
    ])->assertOk();

    $conversation->refresh();
    $draft = (array) data_get($conversation->metadata, 'reservation_draft', []);

    expect($conversation->visitor_name)->toBe('Pierre Jean')
        ->and($draft['service_id'])->toBe($secondService->id)
        ->and($draft['service_name'])->toBe('Coupe premium')
        ->and($draft['preferred_date'])->toBe('2026-09-12')
        ->and($draft['preferred_time'])->toBe('14:30')
        ->and($draft['preferred_team_member_id'])->toBe($teamMember->id)
        ->and($draft['preferred_team_member_name'])->toBe('Alice Tremblay')
        ->and($draft)->not->toHaveKeys(['proposed_slots', 'selected_slot'])
        ->and(data_get($conversation->metadata, 'booking_confirmation'))->toBeNull()
        ->and(data_get($conversation->metadata, 'public_context.selected_service_name'))->toBe('Coupe premium');
});

test('public ai assistant rejects disabled assistants and raw tenant identifiers', function () {
    $owner = User::factory()->create([
        'company_slug' => 'secret-spa',
    ]);
    AiAssistantSetting::factory()->disabled()->create([
        'tenant_id' => $owner->id,
    ]);

    $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => 'secret-spa',
    ])->assertNotFound();

    AiAssistantSetting::query()
        ->where('tenant_id', $owner->id)
        ->update(['enabled' => true]);

    $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => (string) $owner->id,
    ])->assertNotFound();
});
