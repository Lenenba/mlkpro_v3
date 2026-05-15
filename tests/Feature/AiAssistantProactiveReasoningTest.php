<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Modules\AiAssistant\Services\AiReservationOrchestrator;
use Illuminate\Support\Carbon;

function proactiveService(User $tenant, string $name, float $price = 50, ?string $description = null): Product
{
    $category = ProductCategory::factory()->create([
        'user_id' => $tenant->id,
    ]);

    return Product::query()->create([
        'name' => $name,
        'description' => $description ?: $name,
        'category_id' => $category->id,
        'user_id' => $tenant->id,
        'stock' => 0,
        'price' => $price,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
        'unit' => 'service',
    ]);
}

function proactiveTeamMember(User $tenant, Carbon $date, string $start = '09:00:00', string $end = '12:00:00', string $name = 'Service Admin'): TeamMember
{
    $memberUser = User::factory()->create([
        'name' => $name,
    ]);
    $member = TeamMember::factory()->create([
        'account_id' => $tenant->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'is_active' => true,
    ]);

    WeeklyAvailability::query()->create([
        'account_id' => $tenant->id,
        'team_member_id' => $member->id,
        'day_of_week' => $date->dayOfWeek,
        'start_time' => $start,
        'end_time' => $end,
        'is_active' => true,
    ]);

    return $member;
}

test('assistant suggests services from a vague need instead of asking for contact details', function () {
    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    proactiveService($tenant, 'Soin reparateur', 65, 'Traitement pour cheveux secs ou abimes');
    proactiveService($tenant, 'Consultation capillaire', 30, 'Diagnostic et conseils');
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = app(AiAssistantService::class)->handleUserMessage($conversation, 'Mes cheveux sont abimes et cassants');

    expect($response->message)->toContain('Soin reparateur')
        ->and($response->message)->toContain('Consultation capillaire')
        ->and($response->message)->not->toContain('telephone');
});

test('assistant answers price questions before forcing a reservation', function () {
    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    proactiveService($tenant, 'Coupe rapide', 35);
    proactiveService($tenant, 'Soin reparateur', 65);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = app(AiAssistantService::class)->handleUserMessage($conversation, 'Combien coute une coupe?');

    expect($response->message)->toContain('Coupe rapide - 35.00 CAD')
        ->and($response->message)->toContain('Voulez-vous que je verifie les disponibilites')
        ->and($response->status)->toBe(AiConversation::STATUS_OPEN);
});

test('assistant escalates refund and payment conflicts to human review', function () {
    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $response = app(AiAssistantService::class)->handleUserMessage($conversation, 'Je veux annuler mais je veux etre rembourse');

    expect($response->status)->toBe(AiConversation::STATUS_WAITING_HUMAN)
        ->and($response->message)->toContain('remboursement ou le paiement')
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->where('action_type', AiAction::TYPE_REQUEST_HUMAN_REVIEW)->exists())->toBeTrue();
});

test('assistant can recommend the same service as last time when history is enabled', function () {
    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    $service = proactiveService($tenant, 'Coupe premium', 80);
    $customer = Customer::factory()->create([
        'user_id' => $tenant->id,
        'email' => 'client@example.com',
    ]);
    Reservation::factory()->create([
        'account_id' => $tenant->id,
        'client_id' => $customer->id,
        'service_id' => $service->id,
        'starts_at' => now('UTC')->subMonth(),
        'ends_at' => now('UTC')->subMonth()->addHour(),
    ]);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'enable_client_history_recommendations' => true,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $customer->id,
        'visitor_email' => 'client@example.com',
    ]);

    $response = app(AiAssistantService::class)->handleUserMessage($conversation, 'Je veux le meme service que la derniere fois');

    expect($response->message)->toContain('Coupe premium')
        ->and($response->message)->toContain('dernier rendez-vous');
});

test('reservation flow recommends earliest slot when user is flexible', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    $service = proactiveService($tenant, 'Coupe rapide', 35);
    $targetDate = Carbon::parse('2026-05-14', 'UTC');
    proactiveTeamMember($tenant, $targetDate, '09:00:00', '12:00:00');
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'allow_ai_to_choose_earliest_slot' => true,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle(
        $conversation,
        $settings,
        "Je m appelle Jules Roger. Telephone +15145550123. Je veux reserver {$service->name} demain, peu importe l heure.",
        'fr'
    );

    expect($reply)->toContain('Voici 3 creneaux disponibles')
        ->and($reply)->toContain('premier creneau disponible');

    Carbon::setTestNow();
});

test('reservation flow searches evening slots for after work preference', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    $service = proactiveService($tenant, 'Consultation', 45);
    $targetDate = Carbon::parse('2026-05-14', 'UTC');
    proactiveTeamMember($tenant, $targetDate, '17:00:00', '20:00:00');
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
        "Je m appelle Jules Roger. Telephone +15145550123. Je veux reserver {$service->name} demain apres le travail.",
        'fr'
    );

    expect($reply)->toContain('17:00')
        ->and($reply)->toContain('Parfait, je note votre demande pour Consultation le 2026-05-14 après le travail.');

    Carbon::setTestNow();
});

test('assistant proposes alternatives when preferred time has no availability', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    $service = proactiveService($tenant, 'Consultation', 45);
    $targetDate = Carbon::parse('2026-05-14', 'UTC');
    proactiveTeamMember($tenant, $targetDate, '14:00:00', '17:00:00');
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
        "Je m appelle Jules Roger. Telephone +15145550123. Je veux reserver {$service->name} demain matin.",
        'fr'
    );

    expect($reply)->toContain('meilleures alternatives')
        ->and($reply)->toContain('14:00');

    Carbon::setTestNow();
});

test('upsell only appears when enabled', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    $service = proactiveService($tenant, 'Coupe rapide', 35);
    proactiveService($tenant, 'Soin rapide', 20, 'Soin complementaire');
    $targetDate = Carbon::parse('2026-05-14', 'UTC');
    proactiveTeamMember($tenant, $targetDate, '09:00:00', '12:00:00');

    $disabledSettings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'enable_upsell_suggestions' => false,
    ]);
    $disabledConversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);
    $disabledReply = app(AiReservationOrchestrator::class)->handle(
        $disabledConversation,
        $disabledSettings,
        "Je m appelle Jules Roger. Telephone +15145550123. Je veux reserver {$service->name} demain.",
        'fr'
    );

    $disabledSettings->update(['enable_upsell_suggestions' => true]);
    $enabledConversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);
    $enabledReply = app(AiReservationOrchestrator::class)->handle(
        $enabledConversation,
        $disabledSettings->fresh(),
        "Je m appelle Jules Roger. Telephone +15145550123. Je veux reserver {$service->name} demain.",
        'fr'
    );

    expect($disabledReply)->not->toContain('Optionnel')
        ->and($enabledReply)->toContain('Optionnel');

    Carbon::setTestNow();
});

test('staff recommendation respects tenant setting and max suggestion limit', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    $service = proactiveService($tenant, 'Coupe rapide', 35);
    proactiveService($tenant, 'Soin rapide', 20, 'Soin complementaire');
    $targetDate = Carbon::parse('2026-05-14', 'UTC');
    proactiveTeamMember($tenant, $targetDate, '09:00:00', '12:00:00', 'Marc');
    proactiveTeamMember($tenant, $targetDate, '09:00:00', '12:00:00', 'Sarah');

    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'enable_upsell_suggestions' => true,
        'allow_ai_to_recommend_staff' => false,
        'max_suggestions_per_response' => 1,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle(
        $conversation,
        $settings,
        "Je m appelle Jules Roger. Telephone +15145550123. Je veux reserver {$service->name} demain, peu importe.",
        'fr'
    );

    expect($reply)->toContain('premier creneau disponible')
        ->and($reply)->not->toContain('Plusieurs personnes sont disponibles')
        ->and($reply)->not->toContain('Optionnel');

    Carbon::setTestNow();
});

test('assistant asks one focused question and adapts after previous answers', function () {
    $tenant = User::factory()->create(['company_timezone' => 'UTC']);
    proactiveService($tenant, 'Coupe rapide', 35);
    AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);
    $assistant = app(AiAssistantService::class);

    $first = $assistant->handleUserMessage($conversation, 'Je ne sais pas quel service choisir');
    $conversation->refresh();

    expect(substr_count($first->message, '?'))->toBeLessThanOrEqual(1)
        ->and($first->message)->not->toContain('telephone');
});
