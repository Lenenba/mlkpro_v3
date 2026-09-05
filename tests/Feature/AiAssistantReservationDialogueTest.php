<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Services\AiReservationOrchestrator;
use Illuminate\Support\Carbon;

function reservationDialogueContext(array $draft = []): array
{
    $tenant = User::factory()->create(['company_timezone' => 'UTC', 'company_slug' => 'dialogue-salon']);
    $category = ProductCategory::factory()->create(['user_id' => $tenant->id]);
    $service = Product::query()->create([
        'user_id' => $tenant->id,
        'category_id' => $category->id,
        'name' => 'Brushing cheveux longs',
        'price' => 50,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
        'default_language' => 'fr',
        'supported_languages' => ['fr', 'en'],
        'require_human_validation' => true,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
        'intent' => AiConversation::INTENT_RESERVATION,
        'detected_language' => 'fr',
        'metadata' => ['reservation_draft' => array_merge([
            'service_id' => $service->id,
            'service_name' => $service->name,
        ], $draft)],
    ]);

    return [$conversation, $settings, $tenant, $service];
}

test('public reservation dialogue completes a name across messages and remembers tuesday', function () {
    $this->travelTo(Carbon::parse('2026-09-04 08:00:00', 'UTC'));
    [$conversation, $settings, $tenant, $service] = reservationDialogueContext();
    foreach (['Balayage', 'Box braids courtes', 'Box braids longues', 'Brushing cheveux courts'] as $name) {
        $option = $service->replicate();
        $option->name = $name;
        $option->save();
    }
    $created = $this->postJson(route('public.ai-assistant.conversations.store'), [
        'company' => $tenant->company_slug,
        'channel' => AiConversation::CHANNEL_PUBLIC_RESERVATION,
    ])->assertCreated();
    $uuid = $created->json('conversation.uuid');
    $endpoint = route('public.ai-assistant.conversations.messages.store', ['conversation' => $uuid]);

    $steps = [
        ['je voudrais faire une reservation pour mardi', '5. Brushing cheveux longs'],
        ['5', '2026-09-08'],
        ['jules', 'Quel est votre nom de famille?'],
        ['roger', 'numéro de téléphone'],
        ['pierre', 'Jules Roger'],
        ['mon nom complet est Jules Roger Pierre', 'numéro de téléphone'],
        ['5145550123', '2026-09-08'],
    ];
    foreach ($steps as [$message, $expectedReply]) {
        $response = $this->postJson($endpoint, ['message' => $message])->assertOk();
        expect($response->json('messages.1.content'))->toContain($expectedReply);
    }

    $stored = AiConversation::query()->where('public_uuid', $uuid)->firstOrFail();
    expect(data_get($stored->metadata, 'reservation_draft.contact_name'))->toBe('Jules Roger Pierre');
    expect(data_get($stored->metadata, 'reservation_draft.preferred_date'))->toBe('2026-09-08');
    expect(data_get($stored->metadata, 'reservation_draft.service_id'))->toBe($service->id);
    $this->assertDatabaseHas('ai_conversations', ['id' => $stored->id, 'visitor_name' => 'Jules Roger Pierre', 'visitor_phone' => '5145550123']);
    $this->assertDatabaseCount('ai_actions', 0);
    $this->assertDatabaseCount('reservations', 0);
});

test('reservation dialogue combines a surname without replacing or repeating the first name', function (string $message, string $expected) {
    [$conversation, $settings] = reservationDialogueContext(['contact_name' => 'Jules']);

    app(AiReservationOrchestrator::class)->handle($conversation, $settings, $message, 'fr');

    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.contact_name'))->toBe($expected);
})->with([
    'surname' => ['roger', 'Jules roger'],
    'compound surname' => ['de la Cruz', 'Jules de la Cruz'],
    'repeated first name' => ['jules', 'Jules'],
    'full name' => ['Jules Roger', 'Jules Roger'],
    'explicit surname' => ['mon nom de famille est Roger', 'Jules Roger'],
    'explicit correction' => ['mon nom complet est Pierre Martin', 'Pierre Martin'],
    'surname and phone' => ['Roger, 5145550123', 'Jules Roger'],
    'unicode surname' => ['D’Arcy', 'Jules D’Arcy'],
    'surname with article' => ['Le Roux', 'Jules Le Roux'],
    'name resembling a confirmation' => ['mon nom complet est Parfait Martin', 'Parfait Martin'],
]);

test('reservation dialogue does not use conversational replies as a name', function (string $message) {
    $this->travelTo(Carbon::parse('2026-09-04 08:00:00', 'UTC'));
    [$conversation, $settings] = reservationDialogueContext(['contact_name' => 'Jules']);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, $message, 'fr');

    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.contact_name'))->toBe('Jules');
    expect($reply)->toContain('nom de famille');
})->with(['merci', 'oui', 'non', 'bonjour', 'je ne sais pas', 'mardi', 'le matin', 'Brushing cheveux longs', 'je suis disponible mardi']);

test('reservation dialogue keeps dates separate from phone numbers', function (string $message, ?string $phone, ?string $expectedPhone) {
    $this->travelTo(Carbon::parse('2026-09-04 08:00:00', 'UTC'));
    [$conversation, $settings] = reservationDialogueContext(['contact_name' => 'Jules Roger', 'contact_phone' => $phone]);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, $message, 'fr');
    $draft = data_get($conversation->fresh()->metadata, 'reservation_draft');

    expect($draft['contact_phone'])->toBe($expectedPhone);
    expect($draft['preferred_date'])->toBe('2026-09-08');
    expect($reply)->not->toContain('trop court');
})->with([
    'date without phone' => ['2026-09-08', null, null],
    'date preserves phone' => ['2026-09-08', '5145550123', '5145550123'],
    'date and phone together' => ['le 2026-09-08, téléphone 5145550123', null, '5145550123'],
    'french numeric date' => ['08/09/2026', '5145550123', '5145550123'],
]);

test('reservation dialogue understands hour without minutes while waiting for a phone', function () {
    $this->travelTo(Carbon::parse('2026-09-04 08:00:00', 'UTC'));
    [$conversation, $settings] = reservationDialogueContext(['contact_name' => 'Jules Roger']);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, 'mardi à 15h', 'fr');
    $draft = data_get($conversation->fresh()->metadata, 'reservation_draft');

    expect($draft['preferred_date'])->toBe('2026-09-08');
    expect($draft['preferred_time'])->toBe('15:00');
    expect($reply)->toContain('numéro de téléphone')->not->toContain('trop court');
});

test('reservation dialogue does not interpret a formatted phone as a different date', function () {
    $this->travelTo(Carbon::parse('2026-09-04 08:00:00', 'UTC'));
    [$conversation, $settings] = reservationDialogueContext(['contact_name' => 'Jules Roger', 'preferred_date' => '2026-09-08']);

    app(AiReservationOrchestrator::class)->handle($conversation, $settings, '06-12-34-56-78', 'fr');

    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.preferred_date'))->toBe('2026-09-08');
    expect($conversation->visitor_phone)->toBe('06-12-34-56-78');
});

test('reservation dialogue narrows an ambiguous service and maps the displayed choice', function () {
    [$conversation, $settings, $tenant, $longHair] = reservationDialogueContext();
    foreach (['Balayage', 'Brushing cheveux courts'] as $name) {
        $option = $longHair->replicate();
        $option->name = $name;
        $option->save();
    }
    $conversation->update(['metadata' => ['reservation_draft' => ['preferred_date' => '2026-09-08']]]);
    $orchestrator = app(AiReservationOrchestrator::class);

    $reply = $orchestrator->handle($conversation, $settings, 'je voudrais un brushing', 'fr');

    expect($reply)->toContain("1. Brushing cheveux courts\n2. Brushing cheveux longs")->not->toContain('Balayage');
    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.service_id'))->toBeNull();

    $reply = $orchestrator->handle($conversation->fresh(), $settings, '2', 'fr');

    expect($reply)->toContain('Brushing cheveux longs')->toContain('2026-09-08');
    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.service_id'))->toBe($longHair->id);
});

test('reservation dialogue understands a unique service fragment', function () {
    [$conversation, $settings, $tenant, $service] = reservationDialogueContext();
    $conversation->update(['metadata' => []]);

    app(AiReservationOrchestrator::class)->handle($conversation, $settings, 'cheveux longs', 'fr');

    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.service_id'))->toBe($service->id);
});

test('reservation dialogue redisplays corrected contact details and releases an obsolete slot', function (string $correction, string $expectedDate) {
    $this->travelTo(Carbon::parse('2026-09-04 08:00:00', 'UTC'));
    $slot = [
        'index' => 1, 'starts_at' => '2026-09-08T09:00:00+00:00', 'ends_at' => '2026-09-08T10:00:00+00:00',
        'date' => '2026-09-08', 'time' => '09:00', 'team_member_id' => 1, 'duration_minutes' => 60,
    ];
    [$conversation, $settings] = reservationDialogueContext([
        'contact_name' => 'Jules Roger', 'contact_phone' => '5145550123', 'selected_slot' => $slot,
    ]);
    $conversation->update(['metadata' => array_merge($conversation->metadata, [
        'booking_confirmation' => ['summary_shown' => true, 'awaiting_user_confirmation' => true, 'confirmed_by_user' => false],
    ])]);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, 'oui, mon nom complet est Pierre Martin', 'fr');

    expect($reply)->toContain('Pierre Martin')->toContain('Voulez-vous que j’envoie cette demande à l’équipe?');
    expect(data_get($conversation->fresh()->metadata, 'booking_confirmation.awaiting_user_confirmation'))->toBeTrue();
    $this->assertDatabaseCount('ai_actions', 0);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation->fresh(), $settings, $correction, 'fr');

    expect($reply)->toContain($expectedDate)->not->toContain('dès que vous me confirmez');
    expect(data_get($conversation->fresh()->metadata, 'booking_confirmation.awaiting_user_confirmation'))->toBeFalse();
    expect(data_get($conversation->fresh()->metadata, 'reservation_draft.selected_slot'))->toBeNull();
    $this->assertDatabaseCount('ai_actions', 0);
})->with([
    'different day' => ['plutôt mercredi', '2026-09-09'],
    'different part of day' => ['plutôt le soir', '2026-09-08'],
    'different hour' => ['plutôt à 15h', '2026-09-08'],
]);
