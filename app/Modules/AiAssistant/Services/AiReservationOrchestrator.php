<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Product;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Services\ReservationAvailabilityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiReservationOrchestrator
{
    public function __construct(
        private readonly AiActionExecutor $actionExecutor,
        private readonly ReservationAvailabilityService $availabilityService
    ) {}

    public function handle(AiConversation $conversation, AiAssistantSetting $settings, string $message, string $language): string
    {
        $tenant = User::query()->findOrFail((int) $conversation->tenant_id);
        $services = $this->servicesForTenant($tenant);
        $draft = $this->updatedDraft($conversation, $message, $services, $tenant);
        $conversation->update([
            'visitor_name' => $draft['contact_name'] ?? $conversation->visitor_name,
            'visitor_email' => $draft['contact_email'] ?? $conversation->visitor_email,
            'visitor_phone' => $draft['contact_phone'] ?? $conversation->visitor_phone,
            'metadata' => array_merge($conversation->metadata ?? [], [
                'reservation_draft' => $draft,
            ]),
        ]);

        if (! $settings->allow_create_reservation) {
            $this->requestHumanReview($conversation, [
                'reason' => 'reservation_disabled',
                'draft' => $draft,
            ], $settings);

            return $this->line($language, 'reservation_disabled');
        }

        if ($selectedSlot = $this->selectedSlotFromMessage($message, $draft)) {
            return $this->createReservationActions($conversation->fresh() ?? $conversation, $settings, $draft, $selectedSlot, $language);
        }

        $missing = $this->firstMissingField($draft);
        if ($missing) {
            return $this->questionFor($missing, $language, $services);
        }

        if (empty($draft['proposed_slots'])) {
            $slots = $this->proposeSlots($tenant, $draft);
            $draft['proposed_slots'] = $slots;
            $conversation->update([
                'metadata' => array_merge($conversation->metadata ?? [], [
                    'reservation_draft' => $draft,
                ]),
            ]);

            if ($slots === []) {
                return $this->line($language, 'no_slots');
            }

            return $this->formatSlotProposal($slots, $language);
        }

        return $this->line($language, 'choose_slot');
    }

    /**
     * @return Collection<int, Product>
     */
    private function servicesForTenant(User $tenant): Collection
    {
        return Product::query()
            ->services()
            ->where('user_id', (int) $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'price', 'currency_code', 'unit']);
    }

    /**
     * @param  Collection<int, Product>  $services
     * @return array<string, mixed>
     */
    private function updatedDraft(AiConversation $conversation, string $message, Collection $services, User $tenant): array
    {
        $draft = (array) data_get($conversation->metadata, 'reservation_draft', []);
        $text = trim($message);

        if ($email = $this->extractEmail($text)) {
            $draft['contact_email'] = $email;
        }

        if ($phone = $this->extractPhone($text)) {
            $draft['contact_phone'] = $phone;
        }

        if ($name = $this->extractName($text)) {
            $draft['contact_name'] = $name;
        }

        if ($date = $this->extractDate($text, $tenant)) {
            $draft['preferred_date'] = $date;
            unset($draft['proposed_slots']);
        }

        if ($time = $this->extractTime($text)) {
            $draft['preferred_time'] = $time;
        }

        if ($service = $this->matchService($text, $services)) {
            $draft['service_id'] = (int) $service->id;
            $draft['service_name'] = (string) $service->name;
            unset($draft['proposed_slots']);
        } elseif (! isset($draft['service_id']) && $services->count() === 1) {
            $service = $services->first();
            $draft['service_id'] = (int) $service->id;
            $draft['service_name'] = (string) $service->name;
        }

        if (! isset($draft['notes']) && Str::length($text) > 10) {
            $draft['notes'] = $text;
        }

        return $draft;
    }

    private function firstMissingField(array $draft): ?string
    {
        foreach (['service_id', 'contact_name', 'contact_phone', 'contact_email', 'preferred_date'] as $field) {
            if (empty($draft[$field])) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposeSlots(User $tenant, array $draft): array
    {
        $timezone = $this->availabilityService->timezoneForAccount($tenant);
        $date = Carbon::parse((string) $draft['preferred_date'], $timezone);
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            (int) $tenant->id,
            (int) $draft['service_id'],
            null
        );

        $result = $this->availabilityService->generateSlots(
            (int) $tenant->id,
            $date->copy()->startOfDay()->utc(),
            $date->copy()->endOfDay()->utc(),
            $durationMinutes,
            null,
            null,
            null
        );

        $slots = collect($result['slots']);
        if (! empty($draft['preferred_time'])) {
            $preferred = Carbon::parse($draft['preferred_date'].' '.$draft['preferred_time'], $timezone);
            $slots = $slots->sortBy(fn (array $slot): int => abs(Carbon::parse((string) $slot['starts_at'])->diffInMinutes($preferred, false)));
        }

        return $slots
            ->take(3)
            ->values()
            ->map(fn (array $slot, int $index): array => [
                'index' => $index + 1,
                'starts_at' => (string) $slot['starts_at'],
                'ends_at' => (string) ($slot['ends_at'] ?? ''),
                'date' => (string) ($slot['date'] ?? ''),
                'time' => (string) ($slot['time'] ?? ''),
                'team_member_id' => (int) ($slot['team_member_id'] ?? 0),
                'team_member_name' => (string) ($slot['team_member_name'] ?? ''),
                'duration_minutes' => $durationMinutes,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selectedSlotFromMessage(string $message, array $draft): ?array
    {
        $slots = (array) ($draft['proposed_slots'] ?? []);
        if ($slots === []) {
            return null;
        }

        $text = Str::lower($message);
        $selectedIndex = null;

        if (preg_match('/\b([123])\b/', $text, $matches) === 1) {
            $selectedIndex = (int) $matches[1];
        } elseif (Str::contains($text, ['premier', 'first'])) {
            $selectedIndex = 1;
        } elseif (Str::contains($text, ['deuxieme', 'second'])) {
            $selectedIndex = 2;
        } elseif (Str::contains($text, ['troisieme', 'third'])) {
            $selectedIndex = 3;
        }

        if (! $selectedIndex) {
            return null;
        }

        return collect($slots)->first(fn (array $slot): bool => (int) ($slot['index'] ?? 0) === $selectedIndex);
    }

    private function createReservationActions(
        AiConversation $conversation,
        AiAssistantSetting $settings,
        array $draft,
        array $selectedSlot,
        string $language
    ): string {
        $payload = [
            'contact_name' => $draft['contact_name'] ?? null,
            'contact_email' => $draft['contact_email'] ?? null,
            'contact_phone' => $draft['contact_phone'] ?? null,
            'service_id' => (int) ($draft['service_id'] ?? 0),
            'service_name' => $draft['service_name'] ?? null,
            'starts_at' => $selectedSlot['starts_at'] ?? null,
            'ends_at' => $selectedSlot['ends_at'] ?? null,
            'team_member_id' => $selectedSlot['team_member_id'] ?? null,
            'duration_minutes' => $selectedSlot['duration_minutes'] ?? 60,
            'notes' => $draft['notes'] ?? null,
        ];

        if (! $settings->allow_create_prospect) {
            $this->requestHumanReview($conversation, [
                'reason' => 'prospect_creation_disabled',
                'draft' => $draft,
            ], $settings);

            return $this->line($language, 'human_review');
        }

        $pending = (bool) $settings->require_human_validation;
        $prospectAction = $this->actionExecutor->createAction($conversation, AiAction::TYPE_CREATE_PROSPECT, $payload, $pending);
        $reservationAction = $this->actionExecutor->createAction($conversation, AiAction::TYPE_CREATE_RESERVATION, $payload, $pending);

        if ($pending) {
            $conversation->update(['status' => AiConversation::STATUS_WAITING_HUMAN]);

            return $this->line($language, 'pending_review');
        }

        $reservationAction->refresh();
        if ($reservationAction->status === AiAction::STATUS_EXECUTED) {
            return $this->line($language, 'reservation_created');
        }

        $this->requestHumanReview($conversation, [
            'reason' => 'reservation_action_failed',
            'prospect_action_id' => (int) $prospectAction->id,
            'reservation_action_id' => (int) $reservationAction->id,
        ], $settings);

        return $this->line($language, 'human_review');
    }

    private function requestHumanReview(AiConversation $conversation, array $payload, AiAssistantSetting $settings): void
    {
        $this->actionExecutor->createAction($conversation, AiAction::TYPE_REQUEST_HUMAN_REVIEW, $payload, false);
        $conversation->update([
            'status' => AiConversation::STATUS_WAITING_HUMAN,
        ]);
    }

    private function questionFor(string $field, string $language, Collection $services): string
    {
        $isFr = $language === 'fr';

        return match ($field) {
            'service_id' => $isFr
                ? 'Quel service souhaitez-vous reserver? '.($services->isNotEmpty() ? 'Services disponibles: '.$services->pluck('name')->implode(', ').'.' : '')
                : 'Which service would you like to book? '.($services->isNotEmpty() ? 'Available services: '.$services->pluck('name')->implode(', ').'.' : ''),
            'contact_name' => $isFr ? 'Quel est votre nom complet?' : 'What is your full name?',
            'contact_phone' => $isFr ? 'Quel numero de telephone pouvons-nous utiliser?' : 'What phone number can we use?',
            'contact_email' => $isFr ? 'Quelle est votre adresse email?' : 'What email address should we use?',
            'preferred_date' => $isFr ? 'Pour quelle date souhaitez-vous reserver?' : 'What date would you prefer?',
            default => $isFr ? 'Pouvez-vous me donner un peu plus de details?' : 'Could you share a little more detail?',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function formatSlotProposal(array $slots, string $language): string
    {
        $isFr = $language === 'fr';
        $lines = collect($slots)
            ->map(function (array $slot): string {
                $label = trim(($slot['date'] ?? '').' '.($slot['time'] ?? ''));
                $member = trim((string) ($slot['team_member_name'] ?? ''));

                return "{$slot['index']}. {$label}".($member !== '' ? " avec {$member}" : '');
            })
            ->implode("\n");

        return ($isFr ? "Voici 3 creneaux disponibles:\n" : "Here are 3 available slots:\n")
            .$lines."\n"
            .($isFr ? 'Lequel preferez-vous?' : 'Which one do you prefer?');
    }

    private function line(string $language, string $key): string
    {
        $fr = [
            'reservation_disabled' => 'Je vais transmettre votre demande a l equipe pour verification.',
            'no_slots' => 'Je ne vois pas de creneau disponible pour cette date. Voulez-vous essayer une autre date?',
            'choose_slot' => 'Lequel de ces creneaux preferez-vous?',
            'human_review' => 'Je vais transmettre votre demande a l equipe. Elle vous repondra des que possible.',
            'pending_review' => 'Parfait, je transmets cette demande a l equipe pour validation avant confirmation.',
            'reservation_created' => 'Parfait, votre demande de reservation a ete enregistree. L equipe vous confirmera le rendez-vous.',
        ];
        $en = [
            'reservation_disabled' => 'I will send your request to the team for review.',
            'no_slots' => 'I do not see an available slot for that date. Would you like to try another date?',
            'choose_slot' => 'Which of these slots do you prefer?',
            'human_review' => 'I will send your request to the team. They will reply as soon as possible.',
            'pending_review' => 'Perfect, I will send this request to the team for review before confirmation.',
            'reservation_created' => 'Perfect, your booking request has been saved. The team will confirm the appointment.',
        ];

        return ($language === 'fr' ? $fr : $en)[$key] ?? (($language === 'fr') ? $fr['human_review'] : $en['human_review']);
    }

    private function matchService(string $message, Collection $services): ?Product
    {
        $normalized = Str::lower($message);

        return $services->first(function (Product $service) use ($normalized): bool {
            return Str::contains($normalized, Str::lower((string) $service->name));
        });
    }

    private function extractEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[0]);
    }

    private function extractPhone(string $message): ?string
    {
        if (preg_match('/(?:\+?\d[\d\s().-]{6,}\d)/', $message, $matches) !== 1) {
            return null;
        }

        return trim($matches[0]);
    }

    private function extractName(string $message): ?string
    {
        $patterns = [
            '/(?:mon nom est|je m appelle|je suis)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s\'-]{2,80})/iu',
            '/(?:my name is|i am|i\'m)\s+([A-Za-z][A-Za-z\s\'-]{2,80})/iu',
            '/(?:nom|name)\s*:\s*([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s\'-]{2,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function extractDate(string $message, User $tenant): ?string
    {
        $timezone = $this->availabilityService->timezoneForAccount($tenant);
        $text = Str::lower($message);

        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $message, $matches) === 1) {
            return Carbon::parse($matches[1], $timezone)->toDateString();
        }

        if (Str::contains($text, ['demain', 'tomorrow'])) {
            return now($timezone)->addDay()->toDateString();
        }

        if (Str::contains($text, ['aujourd', 'today'])) {
            return now($timezone)->toDateString();
        }

        return null;
    }

    private function extractTime(string $message): ?string
    {
        if (preg_match('/\b([01]?\d|2[0-3])[:h]([0-5]\d)\b/u', $message, $matches) === 1) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT).':'.$matches[2];
        }

        if (preg_match('/\b(1[0-2]|0?[1-9])\s*(am|pm)\b/i', $message, $matches) === 1) {
            $hour = (int) $matches[1];
            if (Str::lower($matches[2]) === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if (Str::lower($matches[2]) === 'am' && $hour === 12) {
                $hour = 0;
            }

            return str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00';
        }

        return null;
    }
}
