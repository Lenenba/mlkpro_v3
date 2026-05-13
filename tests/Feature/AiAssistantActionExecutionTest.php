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
use App\Modules\AiAssistant\Services\AiActionExecutor;
use App\Modules\AiAssistant\Services\AiReservationOrchestrator;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ReservationDatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

function createAiActionService(User $tenant): Product
{
    $category = ProductCategory::factory()->create([
        'user_id' => $tenant->id,
    ]);

    return Product::query()->create([
        'name' => 'Coupe cheveux',
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

function createAiActionMember(User $tenant, Carbon $date): TeamMember
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

function aiReservationPayload(Product $service, TeamMember $member, Carbon $startsAt): array
{
    return [
        'contact_name' => 'Amina Diallo',
        'contact_email' => 'amina@example.com',
        'contact_phone' => '+15145550123',
        'service_id' => $service->id,
        'service_name' => $service->name,
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
        'team_member_id' => $member->id,
        'duration_minutes' => 60,
        'notes' => 'AI booking test.',
    ];
}

test('ai action executor creates a prospect when approved', function () {
    $tenant = User::factory()->create();
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'visitor_name' => 'Amina Diallo',
        'visitor_email' => 'amina@example.com',
    ]);

    $executor = app(AiActionExecutor::class);
    $action = $executor->createAction($conversation, AiAction::TYPE_CREATE_PROSPECT, [
        'contact_name' => 'Amina Diallo',
        'contact_email' => 'amina@example.com',
        'contact_phone' => '+15145550123',
        'service_name' => 'Coupe cheveux',
        'notes' => 'Prospect from AI.',
    ], pending: true);

    expect($action->status)->toBe(AiAction::STATUS_PENDING);

    $executed = $executor->approve($action);
    $conversation->refresh();

    expect($executed->status)->toBe(AiAction::STATUS_EXECUTED)
        ->and($executed->executed_at)->not->toBeNull()
        ->and($executed->output_payload['prospect_id'] ?? null)->toBe($conversation->prospect_id);

    $this->assertDatabaseHas('requests', [
        'id' => $conversation->prospect_id,
        'user_id' => $tenant->id,
        'channel' => 'ai_assistant',
        'contact_email' => 'amina@example.com',
    ]);
});

test('ai reservation orchestration keeps critical actions pending when human validation is required', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiActionService($tenant);
    $targetDate = Carbon::now('UTC')->addDay();
    $member = createAiActionMember($tenant, $targetDate);
    $settings = AiAssistantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'require_human_validation' => true,
    ]);
    $startsAt = $targetDate->copy()->setTime(9, 0);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
        'metadata' => [
            'reservation_draft' => [
                ...aiReservationPayload($service, $member, $startsAt),
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

    $reply = app(AiReservationOrchestrator::class)->handle($conversation, $settings, '1', 'fr');

    $conversation->refresh();

    expect($reply)->toContain('validation')
        ->and($conversation->status)->toBe(AiConversation::STATUS_WAITING_HUMAN)
        ->and(AiAction::query()->where('conversation_id', $conversation->id)->where('status', AiAction::STATUS_PENDING)->count())->toBe(2)
        ->and(Reservation::query()->count())->toBe(0);

    Carbon::setTestNow();
});

test('ai action executor creates a reservation only for a real available slot', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiActionService($tenant);
    $targetDate = Carbon::now('UTC')->addDay();
    $member = createAiActionMember($tenant, $targetDate);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $startsAt = $targetDate->copy()->setTime(9, 0);

    $action = app(AiActionExecutor::class)->createAction(
        $conversation,
        AiAction::TYPE_CREATE_RESERVATION,
        aiReservationPayload($service, $member, $startsAt),
        pending: false
    );

    $conversation->refresh();

    expect($action->status)->toBe(AiAction::STATUS_EXECUTED)
        ->and($conversation->reservation_id)->not->toBeNull()
        ->and($conversation->prospect_id)->not->toBeNull();

    $this->assertDatabaseHas('reservations', [
        'id' => $conversation->reservation_id,
        'account_id' => $tenant->id,
        'team_member_id' => $member->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_PUBLIC_BOOKING,
    ]);

    Carbon::setTestNow();
});

test('ai action executor notifies the owner when a reservation is created', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'in_app' => true,
                'email' => true,
                'notify_on_created' => true,
            ],
        ],
    ]);
    $service = createAiActionService($tenant);
    $targetDate = Carbon::now('UTC')->addDay();
    $member = createAiActionMember($tenant, $targetDate);
    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $startsAt = $targetDate->copy()->setTime(10, 0);

    app(AiActionExecutor::class)->createAction(
        $conversation,
        AiAction::TYPE_CREATE_RESERVATION,
        aiReservationPayload($service, $member, $startsAt),
        pending: false
    );

    Notification::assertSentTo($tenant, ReservationDatabaseNotification::class, function (ReservationDatabaseNotification $notification) {
        return ($notification->payload['event'] ?? null) === 'ai_booking_received';
    });

    Notification::assertSentTo($tenant, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'reservation')
            || str_contains(strtolower($notification->title), 'booking');
    });

    Carbon::setTestNow();
});

test('ai action executor records a failed reservation action when the slot is unavailable', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 08:00:00', 'UTC'));

    $tenant = User::factory()->create([
        'company_timezone' => 'UTC',
    ]);
    $service = createAiActionService($tenant);
    $targetDate = Carbon::now('UTC')->addDay();
    $member = createAiActionMember($tenant, $targetDate);
    $startsAt = $targetDate->copy()->setTime(9, 0);

    Reservation::factory()->create([
        'account_id' => $tenant->id,
        'team_member_id' => $member->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $conversation = AiConversation::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $action = app(AiActionExecutor::class)->createAction(
        $conversation,
        AiAction::TYPE_CREATE_RESERVATION,
        aiReservationPayload($service, $member, $startsAt),
        pending: false
    );

    expect($action->status)->toBe(AiAction::STATUS_FAILED)
        ->and($action->error_message)->not->toBeNull()
        ->and(Reservation::query()->where('account_id', $tenant->id)->count())->toBe(1);

    Carbon::setTestNow();
});
