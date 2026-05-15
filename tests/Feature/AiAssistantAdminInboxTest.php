<?php

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use Illuminate\Support\Carbon;

test('admin can list tenant ai conversations', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $otherOwner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'status' => AiConversation::STATUS_WAITING_HUMAN,
        'intent' => AiConversation::INTENT_RESERVATION,
    ]);
    AiConversation::factory()->create([
        'tenant_id' => $otherOwner->id,
    ]);
    AiMessage::factory()->forConversation($conversation)->create();
    AiAction::factory()->forConversation($conversation)->create([
        'status' => AiAction::STATUS_PENDING,
    ]);

    $this->actingAs($owner)
        ->getJson(route('admin.ai-assistant.conversations.index', [
            'status' => AiConversation::STATUS_WAITING_HUMAN,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'conversations.data')
        ->assertJsonPath('conversations.data.0.id', $conversation->id)
        ->assertJsonPath('conversations.data.0.messages_count', 1)
        ->assertJsonPath('conversations.data.0.pending_actions_count', 1);
});

test('admin can filter tenant ai conversations by date', function () {
    $owner = User::factory()->create([
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $matching = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'created_at' => Carbon::parse('2026-05-13 15:00:00', 'UTC'),
    ]);
    AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'created_at' => Carbon::parse('2026-05-14 15:00:00', 'UTC'),
    ]);

    $this->actingAs($owner)
        ->getJson(route('admin.ai-assistant.conversations.index', [
            'date' => '2026-05-13',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'conversations.data')
        ->assertJsonPath('conversations.data.0.id', $matching->id);
});

test('admin can view a tenant ai conversation detail', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'summary' => 'Client wants a booking.',
    ]);
    AiMessage::factory()->forConversation($conversation)->create([
        'content' => 'Bonjour',
    ]);
    AiMessage::factory()->forConversation($conversation)->assistant()->create([
        'content' => 'Bonjour, comment puis-je vous aider?',
    ]);
    AiAction::factory()->forConversation($conversation)->create([
        'action_type' => AiAction::TYPE_CREATE_PROSPECT,
        'status' => AiAction::STATUS_PENDING,
    ]);

    $this->actingAs($owner)
        ->getJson(route('admin.ai-assistant.conversations.show', $conversation))
        ->assertOk()
        ->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonPath('conversation.summary', 'Client wants a booking.')
        ->assertJsonCount(2, 'conversation.messages')
        ->assertJsonCount(1, 'conversation.actions');
});

test('admin cannot view another tenant ai conversation', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $otherOwner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $otherOwner->id,
    ]);

    $this->actingAs($owner)
        ->getJson(route('admin.ai-assistant.conversations.show', $conversation))
        ->assertForbidden();
});

test('admin can reply as a human in a tenant ai conversation', function () {
    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'status' => AiConversation::STATUS_OPEN,
    ]);

    $this->actingAs($owner)
        ->postJson(route('admin.ai-assistant.conversations.reply', $conversation), [
            'message' => 'Bonjour Amina, nous allons verifier cela.',
        ])
        ->assertCreated()
        ->assertJsonPath('item.sender_type', AiMessage::SENDER_HUMAN)
        ->assertJsonPath('item.content', 'Bonjour Amina, nous allons verifier cela.');

    $conversation->refresh();

    expect($conversation->status)->toBe(AiConversation::STATUS_WAITING_HUMAN);
    $this->assertDatabaseHas('ai_messages', [
        'conversation_id' => $conversation->id,
        'sender_type' => AiMessage::SENDER_HUMAN,
        'content' => 'Bonjour Amina, nous allons verifier cela.',
    ]);
});
