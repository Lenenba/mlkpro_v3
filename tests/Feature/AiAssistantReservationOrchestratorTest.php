<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Services\AiReservationOrchestrator;
use Illuminate\Support\Carbon;

function createAiReservationService(User $tenant, string $name = 'Coupe cheveux'): Product
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

function createAiReservationTeamMember(User $tenant, Carbon $date): TeamMember
{
    $member = TeamMember::factory()->create([
        'account_id' => $tenant->id,
        'role' => 'member',
        'is_active' => true,
    ]);

    WeeklyAvailability::query()->create([
        'account_id' => $tenant->id,
        'team_member_id' => $member->id,
        'day_of_week' => $date->dayOfWeek,
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
        'is_active' => true,
    ]);

    return $member;
}

test('ai reservation orchestrator asks for one missing field at a time', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $reply = app(AiReservationOrchestrator::class)
        ->handle($conversation, $settings, 'Bonjour, je veux reserver.', 'fr');

    expect($reply)->toContain('Quel service souhaitez-vous reserver?')
        ->and(AiAction::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);
});

test('ai reservation orchestrator proposes real available slots', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant);
    $targetDate = Carbon::now('UTC')->addDay();
    $member = createAiReservationTeamMember($tenant, $targetDate);
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle(
        $conversation,
        $settings,
        "Je m appelle Amina Diallo. Email amina@example.com. Telephone +15145550123. Je veux reserver {$service->name} demain.",
        'fr'
    );

    $conversation->refresh();
    $slots = data_get($conversation->metadata, 'reservation_draft.proposed_slots', []);

    expect($reply)->toContain('Voici 3 creneaux disponibles')
        ->and($slots)->toHaveCount(3)
        ->and($slots[0]['team_member_id'])->toBe($member->id)
        ->and($slots[0]['starts_at'])->toBe($targetDate->copy()->setTime(9, 0)->toIso8601String())
        ->and($conversation->visitor_email)->toBe('amina@example.com')
        ->and(AiAction::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);

    Carbon::setTestNow();
});

test('ai reservation orchestrator does not invent slots when none are available', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant);
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle(
        $conversation,
        $settings,
        "Je m appelle Amina Diallo. Email amina@example.com. Telephone +15145550123. Je veux reserver {$service->name} demain.",
        'fr'
    );

    $conversation->refresh();

    expect($reply)->toContain('Je ne vois pas de creneau disponible')
        ->and(data_get($conversation->metadata, 'reservation_draft.proposed_slots'))->toBe([])
        ->and(AiAction::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);

    Carbon::setTestNow();
});
