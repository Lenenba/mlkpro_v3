<?php

namespace App\Modules\AiAssistant\Actions;

use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ReservationDatabaseNotification;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationPreferenceService;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CreateReservationFromAiAction
{
    public function __construct(
        private readonly CreateProspectFromAiAction $createProspect,
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationPreferenceService $notificationPreferences
    ) {}

    public function execute(AiAction $action): Reservation
    {
        $conversation = $action->conversation()->firstOrFail();
        $tenant = User::query()->findOrFail((int) $action->tenant_id);
        $payload = $action->input_payload ?? [];
        $serviceId = (int) Arr::get($payload, 'service_id');
        $startsAt = (string) Arr::get($payload, 'starts_at');

        if ($serviceId <= 0 || $startsAt === '') {
            throw ValidationException::withMessages([
                'reservation' => ['A service and start time are required before creating a reservation.'],
            ]);
        }

        $service = Product::query()
            ->services()
            ->where('user_id', (int) $tenant->id)
            ->where('is_active', true)
            ->whereKey($serviceId)
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                'service_id' => ['Selected service is not available.'],
            ]);
        }

        $prospect = $conversation->prospect_id
            ? $conversation->prospect()->first()
            : $this->createProspect->execute($action);

        $slot = $this->resolveAvailableSlot($tenant, $service, $payload);
        $timezone = $this->availabilityService->timezoneForAccount($tenant);
        $durationMinutes = (int) ($payload['duration_minutes'] ?? 60);

        $reservation = $this->availabilityService->book([
            'account_id' => (int) $tenant->id,
            'team_member_id' => (int) $slot['team_member_id'],
            'service_id' => (int) $service->id,
            'prospect_id' => $prospect?->id,
            'starts_at' => (string) $slot['starts_at'],
            'ends_at' => (string) ($slot['ends_at'] ?? Carbon::parse((string) $slot['starts_at'])->addMinutes($durationMinutes)->toIso8601String()),
            'duration_minutes' => $durationMinutes,
            'timezone' => $timezone,
            'status' => Reservation::STATUS_PENDING,
            'source' => Reservation::SOURCE_PUBLIC_BOOKING,
            'client_notes' => Arr::get($payload, 'notes'),
            'metadata' => [
                'ai_assistant' => [
                    'conversation_id' => (int) $conversation->id,
                    'action_id' => (int) $action->id,
                    'contact_name' => $prospect?->contact_name,
                    'contact_email' => $prospect?->contact_email,
                    'contact_phone' => $prospect?->contact_phone,
                    'service_address' => Arr::get($payload, 'service_address'),
                    'team_member_name' => $slot['team_member_name'] ?? null,
                ],
            ],
        ], $tenant);

        $conversation->update([
            'reservation_id' => (int) $reservation->id,
            'prospect_id' => $prospect?->id,
            'status' => 'resolved',
        ]);

        $reservation = $reservation->fresh(['service:id,name', 'teamMember.user:id,name,email', 'prospect:id,contact_name,contact_email,contact_phone'])
            ?? $reservation;

        $this->notifyTenant($tenant, $conversation, $reservation);

        return $reservation;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveAvailableSlot(User $tenant, Product $service, array $payload): array
    {
        $timezone = $this->availabilityService->timezoneForAccount($tenant);
        $startsAt = Carbon::parse((string) $payload['starts_at'], $timezone)->utc();
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            (int) $tenant->id,
            (int) $service->id,
            isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : null
        );
        $teamMemberId = ! empty($payload['team_member_id']) ? (int) $payload['team_member_id'] : null;
        $localDay = $startsAt->copy()->setTimezone($timezone);

        $result = $this->availabilityService->generateSlots(
            (int) $tenant->id,
            $localDay->copy()->startOfDay()->utc(),
            $localDay->copy()->endOfDay()->utc(),
            $durationMinutes,
            $teamMemberId,
            null,
            null
        );

        $slot = collect($result['slots'])
            ->first(fn (array $candidate): bool => Carbon::parse((string) $candidate['starts_at'])->utc()->equalTo($startsAt));

        if (! $slot) {
            throw ValidationException::withMessages([
                'starts_at' => ['Selected slot is no longer available.'],
            ]);
        }

        return $slot;
    }

    private function notifyTenant(User $tenant, AiConversation $conversation, Reservation $reservation): void
    {
        $settings = $this->notificationPreferences->resolveFor($tenant);
        if (empty($settings['enabled']) || empty($settings['notify_on_created'])) {
            return;
        }

        $reservation->loadMissing([
            'teamMember.user:id,name,email',
            'service:id,name',
            'prospect:id,contact_name,contact_email,contact_phone',
        ]);

        $isFr = str_starts_with(LocalePreference::forUser($tenant), 'fr');
        $title = $isFr ? 'Nouvelle reservation IA' : 'New AI booking';
        $message = $isFr
            ? 'Une reservation a ete creee par Malikia AI Assistant.'
            : 'A booking was created by Malikia AI Assistant.';
        $actionUrl = route('admin.ai-assistant.conversations.show', $conversation);
        $localStartsAt = $reservation->starts_at
            ? $reservation->starts_at
                ->copy()
                ->setTimezone($tenant->company_timezone ?: config('app.timezone', 'UTC'))
                ->format('Y-m-d H:i')
            : '-';
        $details = [
            ['label' => $isFr ? 'Service' : 'Service', 'value' => $reservation->service?->name ?: '-'],
            [
                'label' => $isFr ? 'Client' : 'Client',
                'value' => $reservation->prospect?->contact_name ?: ($isFr ? 'Visiteur' : 'Visitor'),
            ],
            ['label' => $isFr ? 'Quand' : 'When', 'value' => $localStartsAt],
            ['label' => $isFr ? 'Statut' : 'Status', 'value' => $reservation->status],
        ];
        $context = [
            'reservation_id' => $reservation->id,
            'prospect_id' => $reservation->prospect_id,
            'ai_conversation_id' => $conversation->id,
            'event' => 'ai_booking_received',
        ];

        $recipients = collect([$tenant, $reservation->teamMember?->user])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id');

        foreach ($recipients as $recipient) {
            if ((bool) ($settings['in_app'] ?? false)) {
                NotificationDispatcher::send($recipient, new ReservationDatabaseNotification([
                    'title' => $title,
                    'message' => $message,
                    'event' => 'ai_booking_received',
                    'action_url' => $actionUrl,
                    'reservation_id' => $reservation->id,
                    'prospect_id' => $reservation->prospect_id,
                    'ai_conversation_id' => $conversation->id,
                    'status' => $reservation->status,
                    'starts_at' => $reservation->starts_at?->toIso8601String(),
                ]), $context);
            }

            if ((bool) ($settings['email'] ?? false) && filled($recipient->email)) {
                NotificationDispatcher::send($recipient, new ActionEmailNotification(
                    $title,
                    $message,
                    $details,
                    $actionUrl,
                    $isFr ? 'Ouvrir la conversation' : 'Open conversation',
                    $title
                ), $context);
            }
        }
    }
}
