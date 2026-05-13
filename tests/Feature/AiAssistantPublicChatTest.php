<?php

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use Inertia\Testing\AssertableInertia as Assert;

test('public ai assistant page renders the chat shell', function () {
    $owner = User::factory()->create([
        'company_slug' => 'studio-lumiere',
        'company_name' => 'Studio Lumiere',
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
            ->where('assistant.name', 'Reception Lumiere')
            ->where('endpoints.create', route('public.ai-assistant.conversations.store'))
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
