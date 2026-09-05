<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use Illuminate\Support\Carbon;

function createAiInboxService(User $owner, string $name): Product
{
    $category = ProductCategory::factory()->create([
        'user_id' => $owner->id,
    ]);

    return Product::query()->create([
        'name' => $name,
        'description' => $name,
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'stock' => 0,
        'price' => 7500,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
        'unit' => 'service',
    ]);
}

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

test('admin can search tenant ai conversations across contact booking and message data', function () {
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
    $service = createAiInboxService($owner, 'Soin Signature Inbox');
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'public_uuid' => '9d951274-11c4-4fd2-aaf6-01c6c18b3ec7',
        'visitor_name' => 'Amina RechercheUnique',
        'visitor_email' => 'amina.inbox@example.test',
        'visitor_phone' => '+1 514 555 0199',
        'reservation_id' => $reservation->id,
    ]);
    AiMessage::factory()->forConversation($conversation)->create([
        'content' => 'Le mot secret est heliotrope-inbox.',
    ]);
    AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'visitor_name' => 'Conversation sans correspondance',
    ]);
    $foreignConversation = AiConversation::factory()->create([
        'tenant_id' => $otherOwner->id,
    ]);

    $queries = [
        'Amina RechercheUnique',
        'amina.inbox@example.test',
        '+1 514 555 0199',
        '9d951274-11c4-4fd2-aaf6-01c6c18b3ec7',
        '#'.$reservation->id,
        'heliotrope-inbox',
        'Soin Signature Inbox',
    ];

    AiMessage::factory()->forConversation($foreignConversation)->create([
        'content' => implode(' ', $queries),
    ]);

    foreach ($queries as $query) {
        $response = $this->actingAs($owner)
            ->getJson(route('admin.ai-assistant.conversations.index', ['q' => $query]));

        $response
            ->assertOk()
            ->assertJsonPath('filters.q', $query)
            ->assertJsonCount(1, 'conversations.data')
            ->assertJsonPath('conversations.data.0.id', $conversation->id);
    }
});

test('admin inbox sorts by last message activity and includes reservation preview data', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-04 16:00:00', 'UTC'));

    $owner = User::factory()->create([
        'company_features' => [
            'assistant' => true,
        ],
    ]);
    $service = createAiInboxService($owner, 'Consultation Inbox');
    $startsAt = Carbon::parse('2026-09-08 14:30:00', 'UTC');
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
    ]);
    $activeConversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'public_uuid' => '4065ef7e-97bc-4915-aa56-f785835c3236',
        'visitor_email' => 'active@example.test',
        'visitor_phone' => '+1 438 555 0112',
        'reservation_id' => $reservation->id,
        'created_at' => now()->subMonth(),
        'updated_at' => now()->subMonth(),
    ]);
    $misleadingConversation = AiConversation::factory()->create([
        'tenant_id' => $owner->id,
        'created_at' => now()->subDay(),
        'updated_at' => now(),
    ]);
    $latestMessageAt = now()->subMinutes(5);

    AiMessage::factory()->forConversation($activeConversation)->create([
        'created_at' => $latestMessageAt,
        'updated_at' => $latestMessageAt,
    ]);
    AiMessage::factory()->forConversation($misleadingConversation)->create([
        'created_at' => now()->subWeek(),
        'updated_at' => now()->subWeek(),
    ]);

    $this->actingAs($owner)
        ->getJson(route('admin.ai-assistant.conversations.index'))
        ->assertOk()
        ->assertJsonPath('conversations.data.0.id', $activeConversation->id)
        ->assertJsonPath('conversations.data.1.id', $misleadingConversation->id)
        ->assertJsonPath('conversations.data.0.public_uuid', $activeConversation->public_uuid)
        ->assertJsonPath('conversations.data.0.visitor_email', 'active@example.test')
        ->assertJsonPath('conversations.data.0.visitor_phone', '+1 438 555 0112')
        ->assertJsonPath('conversations.data.0.last_message_at', $latestMessageAt->toIso8601String())
        ->assertJsonPath('conversations.data.0.last_activity_at', $latestMessageAt->toIso8601String())
        ->assertJsonPath('conversations.data.0.reservation.id', $reservation->id)
        ->assertJsonPath('conversations.data.0.reservation.status', Reservation::STATUS_CONFIRMED)
        ->assertJsonPath('conversations.data.0.reservation.service_name', 'Consultation Inbox')
        ->assertJsonPath('conversations.data.0.reservation.starts_at', $startsAt->toIso8601String());
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
