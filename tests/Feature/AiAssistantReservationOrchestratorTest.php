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

    expect($reply)->toContain('Quel service souhaitez-vous réserver?')
        ->and(AiAction::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);
});

test('ai reservation orchestrator explains selected service naturally and asks coherent contact fields', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    createAiReservationService($tenant, 'Deep clean package');
    createAiReservationService($tenant, 'Pressure wash');
    createAiReservationService($tenant, 'Window cleaning');
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, '2', 'fr');

    expect($reply)->toContain('Parfait, vous souhaitez réserver le service Pressure wash.')
        ->and($reply)->toContain('Pour préparer la demande, j’ai besoin de votre nom complet et d’un numéro de téléphone.')
        ->and($reply)->not->toContain('Je comprends:')
        ->and($reply)->not->toContain('seulement le nom')
        ->and(AiAction::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);
});

test('ai reservation orchestrator uses first name context without pretending only one field is missing', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    createAiReservationService($tenant, 'Deep clean package');
    createAiReservationService($tenant, 'Pressure wash');
    createAiReservationService($tenant, 'Window cleaning');
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [
            'reservation_draft' => [
                'contact_name' => 'Jules',
            ],
        ],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, '2', 'fr');

    expect($reply)->toContain('Parfait, vous souhaitez réserver le service Pressure wash.')
        ->and($reply)->toContain('J’ai déjà votre prénom, Jules. Pouvez-vous me donner votre nom complet et un numéro de téléphone?')
        ->and($reply)->not->toContain('Il me manque seulement le nom');
});

test('ai reservation orchestrator asks for missing phone only with coherent wording', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant, 'Consultation');
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [
            'reservation_draft' => [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'contact_name' => 'Jules Roger',
                'preferred_date' => '2026-05-14',
            ],
        ],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, 'merci', 'fr');

    expect($reply)->toContain('Merci Jules Roger. Il me manque seulement un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.')
        ->and($reply)->not->toContain('nom complet');
});

test('ai reservation orchestrator asks for service address when pressure wash needs an intervention address', function () {
    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant, 'Pressure wash');
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [
            'reservation_draft' => [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'contact_name' => 'Jules Roger',
                'contact_phone' => '5145499697',
                'preferred_date' => '2026-05-14',
            ],
        ],
    ]);

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, 'merci', 'fr');

    expect($reply)->toContain('Pour le service Pressure wash, il me manque l’adresse où l’intervention doit être effectuée.');
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

test('ai reservation orchestrator shows a french summary before creating a reservation request', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant, 'Consultation');
    $targetDate = Carbon::parse('2026-05-14', 'UTC');
    $member = createAiReservationTeamMember($tenant, $targetDate);
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'require_human_validation' => false,
    ]);
    $startsAt = $targetDate->copy()->setTime(9, 0);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [
            'reservation_draft' => [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'contact_name' => 'Jules Roger',
                'contact_phone' => '5145499697',
                'preferred_date' => $targetDate->toDateString(),
                'proposed_slots' => [
                    [
                        'index' => 1,
                        'starts_at' => $startsAt->toIso8601String(),
                        'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
                        'date' => $startsAt->toDateString(),
                        'time' => $startsAt->format('H:i'),
                        'team_member_id' => $member->id,
                        'team_member_name' => $member->user?->name,
                        'duration_minutes' => 60,
                    ],
                ],
            ],
        ],
    ]);

    $summaryReply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, '1', 'fr');
    $conversation->refresh();

    expect($summaryReply)->toContain('Voici le résumé de votre demande')
        ->and($summaryReply)->toContain('- Service : Consultation')
        ->and($summaryReply)->toContain('- Nom : Jules Roger')
        ->and($summaryReply)->toContain('- Téléphone : 514-549-9697')
        ->and($summaryReply)->toContain('- Date souhaitée : jeudi 14 mai 2026')
        ->and($summaryReply)->toContain('- Heure : 09:00')
        ->and($summaryReply)->toContain('- Avec :')
        ->and($summaryReply)->toContain('Voulez-vous que j’envoie cette demande à l’équipe?')
        ->and(data_get($conversation->metadata, 'booking_confirmation'))->toBe([
            'summary_shown' => true,
            'awaiting_user_confirmation' => true,
            'confirmed_by_user' => false,
        ])
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);

    $finalReply = app(AiReservationOrchestrator::class)->handle($conversation->fresh() ?? $conversation, $settings, 'oui', 'fr');
    $conversation->refresh();

    expect($finalReply)->toContain('C’est noté. Votre demande de réservation pour Consultation a été envoyée à l’équipe.')
        ->and($finalReply)->toContain('Ils vous contacteront au 514-549-9697')
        ->and($finalReply)->not->toContain('C’est confirmé')
        ->and($finalReply)->not->toContain('Votre rendez-vous pour Consultation est prévu')
        ->and(data_get($conversation->metadata, 'booking_confirmation.confirmed_by_user'))->toBeTrue()
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->where('action_type', AiAction::TYPE_CREATE_RESERVATION)->count())->toBe(1)
        ->and(Reservation::query()->count())->toBe(1)
        ->and(Reservation::query()->first()?->status)->toBe(Reservation::STATUS_PENDING);

    Carbon::setTestNow();
});

test('ai reservation orchestrator understands french weekday dates', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant);
    $targetDate = Carbon::parse('2026-05-16', 'UTC');
    createAiReservationTeamMember($tenant, $targetDate);
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
        "Je m appelle Amina Diallo. Email amina@example.com. Telephone +15145550123. Je veux reserver {$service->name} ce samedi.",
        'fr'
    );

    $conversation->refresh();
    $slots = data_get($conversation->metadata, 'reservation_draft.proposed_slots', []);

    expect($reply)->toContain('Voici 3 creneaux disponibles')
        ->and(data_get($conversation->metadata, 'reservation_draft.preferred_date'))->toBe('2026-05-16')
        ->and($slots[0]['starts_at'])->toBe($targetDate->copy()->setTime(9, 0)->toIso8601String());

    Carbon::setTestNow();
});

test('ai reservation orchestrator understands broad french date ranges', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiReservationService($tenant);
    $targetDate = Carbon::parse('2026-05-18', 'UTC');
    createAiReservationTeamMember($tenant, $targetDate);
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
        "Je m appelle Amina Diallo. Email amina@example.com. Telephone +15145550123. Je veux reserver {$service->name} la semaine prochaine.",
        'fr'
    );

    $conversation->refresh();
    $slots = data_get($conversation->metadata, 'reservation_draft.proposed_slots', []);

    expect($reply)->toContain('Voici 3 creneaux disponibles')
        ->and(data_get($conversation->metadata, 'reservation_draft.preferred_date_start'))->toBe('2026-05-18')
        ->and(data_get($conversation->metadata, 'reservation_draft.preferred_date_end'))->toBe('2026-05-24')
        ->and($slots[0]['starts_at'])->toBe($targetDate->copy()->setTime(9, 0)->toIso8601String());

    $endOfMonthDate = Carbon::parse('2026-05-25', 'UTC');
    createAiReservationTeamMember($tenant, $endOfMonthDate);
    $endOfMonthConversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [],
    ]);

    $endOfMonthReply = app(AiReservationOrchestrator::class)->handle(
        $endOfMonthConversation,
        $settings,
        "Je m appelle Amina Diallo. Email amina@example.com. Telephone +15145550123. Je veux reserver {$service->name} a la fin de mois.",
        'fr'
    );

    $endOfMonthConversation->refresh();
    $endOfMonthSlots = data_get($endOfMonthConversation->metadata, 'reservation_draft.proposed_slots', []);

    expect($endOfMonthReply)->toContain('Voici 3 creneaux disponibles')
        ->and(data_get($endOfMonthConversation->metadata, 'reservation_draft.preferred_date_start'))->toBe('2026-05-25')
        ->and(data_get($endOfMonthConversation->metadata, 'reservation_draft.preferred_date_end'))->toBe('2026-05-31')
        ->and($endOfMonthSlots[0]['starts_at'])->toBe($endOfMonthDate->copy()->setTime(9, 0)->toIso8601String());

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

    expect($reply)->toContain('Je ne vois pas de créneau disponible')
        ->and(data_get($conversation->metadata, 'reservation_draft.proposed_slots'))->toBe([])
        ->and(AiAction::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(0);

    Carbon::setTestNow();
});
