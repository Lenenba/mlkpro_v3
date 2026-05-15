<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Services\ReservationAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiReservationOrchestrator
{
    public function __construct(
        private readonly AiActionExecutor $actionExecutor,
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ContextualRecommendationEngine $recommendationEngine,
        private readonly ProactiveSuggestionEngine $suggestionEngine,
        private readonly MissingInformationMessageBuilder $missingInformationMessageBuilder,
        private readonly BookingSummaryBuilder $bookingSummaryBuilder
    ) {}

    public function handle(AiConversation $conversation, AiAssistantSetting $settings, string $message, string $language): string
    {
        $tenant = User::query()->findOrFail((int) $conversation->tenant_id);
        $services = $this->servicesForTenant($tenant);
        $previousDraft = (array) data_get($conversation->metadata, 'reservation_draft', []);
        $expectedField = $this->firstMissingField($previousDraft);
        $draft = $this->updatedDraft($conversation, $message, $services, $tenant);
        $recommendations = $this->recommendationEngine->analyze($conversation, $settings, $tenant, $services, $draft, $message, $language);
        $draft = $this->recommendationEngine->applyToDraft($draft, $recommendations);
        $warmOpening = $this->warmOpeningFor($conversation, $previousDraft, $draft, $message, $language);
        $serviceAcknowledgement = $this->missingInformationMessageBuilder->selectedServiceAcknowledgement($previousDraft, $draft, $language);
        $opening = $this->joinSentences($warmOpening, $serviceAcknowledgement);

        $conversation->update([
            'visitor_name' => $draft['contact_name'] ?? $conversation->visitor_name,
            'visitor_email' => $draft['contact_email'] ?? $conversation->visitor_email,
            'visitor_phone' => $draft['contact_phone'] ?? $conversation->visitor_phone,
            'metadata' => array_merge($conversation->metadata ?? [], [
                'reservation_draft' => $draft,
            ]),
        ]);

        $freshConversation = $conversation->fresh() ?? $conversation;
        $confirmation = (array) data_get($freshConversation->metadata, 'booking_confirmation', []);
        if ($this->isAwaitingBookingConfirmation($confirmation)) {
            if ($this->isPositiveConfirmation($message)) {
                $missingFields = $this->missingFields($draft);
                $selectedSlot = (array) ($draft['selected_slot'] ?? []);

                if ($missingFields !== []) {
                    return $opening.$this->missingInformationMessageBuilder->build($draft, $missingFields, $services, $language);
                }

                if ($selectedSlot === []) {
                    return $opening.$this->line($language, 'choose_slot');
                }

                $this->updateConversationMetadata($freshConversation, [
                    'reservation_draft' => $draft,
                    'booking_confirmation' => [
                        'summary_shown' => true,
                        'awaiting_user_confirmation' => false,
                        'confirmed_by_user' => true,
                    ],
                ]);

                return $this->createReservationActions($freshConversation->fresh() ?? $freshConversation, $settings, $draft, $selectedSlot, $language);
            }

            if ($this->isNegativeConfirmation($message)) {
                $this->updateConversationMetadata($freshConversation, [
                    'booking_confirmation' => [
                        'summary_shown' => true,
                        'awaiting_user_confirmation' => false,
                        'confirmed_by_user' => false,
                    ],
                ]);

                return $language === 'fr'
                    ? 'Pas de souci. Que souhaitez-vous modifier dans la demande?'
                    : 'No problem. What would you like to change in the request?';
            }

            return $language === 'fr'
                ? 'Je peux l’envoyer à l’équipe dès que vous me confirmez. Vous pouvez répondre oui, ok, parfait ou confirme.'
                : 'I can send it to the team once you confirm. You can reply yes, ok, perfect, or confirm.';
        }

        if ($selectedSlot = $this->selectedSlotFromMessage($message, $draft)) {
            $draft['selected_slot'] = $selectedSlot;
            $missingFields = $this->missingFields($draft);

            $this->updateConversationMetadata($freshConversation, [
                'reservation_draft' => $draft,
                'booking_confirmation' => [
                    'summary_shown' => true,
                    'awaiting_user_confirmation' => $missingFields === [],
                    'confirmed_by_user' => false,
                ],
            ]);

            $timezone = $this->availabilityService->timezoneForAccount($tenant);

            return $opening.$this->bookingSummaryBuilder->build(
                $draft,
                $selectedSlot,
                $missingFields,
                $language,
                $timezone,
                $this->willAutoConfirmReservation($settings)
            );
        }

        $missingFields = $this->missingFields($draft);
        if ($missingFields !== []) {
            $missing = $missingFields[0];
            if ($expectedField !== 'service_id' && in_array('contact_phone', $missingFields, true) && $this->looksLikeShortPhoneAttempt($message)) {
                return $opening.$this->line($language, 'invalid_phone');
            }

            if ($missing === 'contact_email' && $expectedField === 'contact_email' && $this->looksLikeEmailAttempt($message)) {
                return $opening.$this->line($language, 'invalid_email');
            }

            if ($missing === 'preferred_date' && $expectedField === 'preferred_date' && $this->looksLikeDateAttempt($message)) {
                return $opening.$this->line($language, 'invalid_date');
            }

            return $opening.$this->missingInformationMessageBuilder->build($draft, $missingFields, $services, $language);
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
                $alternativeSlots = $this->proposeAlternativeSlots($tenant, $draft);
                if ($alternativeSlots !== []) {
                    return $warmOpening.$this->summaryLine($draft, $language).$this->formatAlternativeSlotProposal($alternativeSlots, $language);
                }

                return $warmOpening.$this->summaryLine($draft, $language).$this->line($language, 'no_slots');
            }

            $suggestions = $this->suggestionEngine->suggestions(
                $conversation,
                $settings,
                $tenant,
                $services,
                $draft,
                $recommendations,
                $language,
                $slots
            );

            return $warmOpening.$this->summaryLine($draft, $language).$this->formatSlotProposal($slots, $language, $suggestions);
        }

        return $warmOpening.$this->line($language, 'choose_slot');
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
        $expectedField = $this->firstMissingField($draft);

        if ($email = $this->extractEmail($text)) {
            $draft['contact_email'] = $email;
        }

        if ($phone = $this->extractPhone($text)) {
            $draft['contact_phone'] = $phone;
        }

        if ($name = $this->extractName($text)) {
            $draft['contact_name'] = $name;
        } elseif ($expectedField === 'contact_name' && ($name = $this->plainTextName($this->stripContactDetails($text, $phone, $email)))) {
            $draft['contact_name'] = $name;
        }

        if ($dateSelection = $this->extractDateSelection($text, $tenant)) {
            unset($draft['preferred_date'], $draft['preferred_date_start'], $draft['preferred_date_end'], $draft['preferred_date_label']);
            $draft = array_merge($draft, $dateSelection);
            unset($draft['proposed_slots'], $draft['selected_slot']);
        }

        if ($time = $this->extractTime($text)) {
            $draft['preferred_time'] = $time;
            unset($draft['proposed_slots'], $draft['selected_slot']);
        }

        if ($service = $this->matchServiceSelection($text, $services, $expectedField)) {
            $draft['service_id'] = (int) $service->id;
            $draft['service_name'] = (string) $service->name;
            unset($draft['proposed_slots'], $draft['selected_slot']);
        }

        if ($address = $this->extractAddress($text, $expectedField, $draft)) {
            $draft['service_address'] = $address;
        }

        if (! isset($draft['notes']) && $expectedField === null && Str::length($text) > 10) {
            $draft['notes'] = $text;
        }

        return $draft;
    }

    private function firstMissingField(array $draft): ?string
    {
        return $this->missingFields($draft)[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    private function missingFields(array $draft): array
    {
        $fields = [];

        if (empty($draft['service_id'])) {
            $fields[] = 'service_id';

            return $fields;
        }

        if (! $this->hasCompleteContactName((string) ($draft['contact_name'] ?? ''))) {
            $fields[] = 'contact_name';
        }

        if (empty($draft['contact_phone'])) {
            $fields[] = 'contact_phone';
        }

        if ($this->requiresServiceAddress($draft) && empty($draft['service_address'])) {
            $fields[] = 'service_address';
        }

        $hasSelectedSlotDate = ! empty(data_get($draft, 'selected_slot.starts_at'));
        if (! $hasSelectedSlotDate && empty($draft['preferred_date']) && (empty($draft['preferred_date_start']) || empty($draft['preferred_date_end']))) {
            $fields[] = 'preferred_date';
        }

        return $fields;
    }

    private function hasCompleteContactName(string $name): bool
    {
        $parts = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->map(fn (string $part): string => trim($part, " \t\n\r\0\x0B,.;:!?"))
            ->filter(fn (string $part): bool => $part !== '');

        return $parts->count() >= 2;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function requiresServiceAddress(array $draft): bool
    {
        $serviceName = Str::ascii(Str::lower((string) ($draft['service_name'] ?? '')));
        if ($serviceName === '') {
            return false;
        }

        return Str::contains($serviceName, [
            'pressure wash',
            'power wash',
            'lavage pression',
            'nettoyage pression',
            'window cleaning',
            'vitres',
            'deep clean',
            'cleaning',
            'nettoyage',
            'domicile',
            'maison',
            'residential',
            'commercial',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposeSlots(User $tenant, array $draft): array
    {
        $timezone = $this->availabilityService->timezoneForAccount($tenant);
        $startsAt = $this->slotSearchStart($draft, $timezone);
        $endsAt = $this->slotSearchEnd($draft, $timezone);
        $durationMinutes = $this->availabilityService->resolveDurationMinutes(
            (int) $tenant->id,
            (int) $draft['service_id'],
            null
        );

        $result = $this->availabilityService->generateSlots(
            (int) $tenant->id,
            $startsAt->copy()->utc(),
            $endsAt->copy()->utc(),
            $durationMinutes,
            null,
            null,
            null
        );

        $slots = collect($result['slots']);
        if (! empty($draft['preferred_time_start']) && ! empty($draft['preferred_time_end'])) {
            $startTime = (string) $draft['preferred_time_start'];
            $endTime = (string) $draft['preferred_time_end'];
            $slots = $slots->filter(function (array $slot) use ($timezone, $startTime, $endTime): bool {
                $slotTime = Carbon::parse((string) $slot['starts_at'])->setTimezone($timezone)->format('H:i');

                return $slotTime >= $startTime && $slotTime <= $endTime;
            });
        }

        if (! empty($draft['preferred_time'])) {
            $preferredDate = (string) ($draft['preferred_date'] ?? $draft['preferred_date_start']);
            $preferred = Carbon::parse($preferredDate.' '.$draft['preferred_time'], $timezone);
            $slots = $slots->sortBy(fn (array $slot): int => abs(Carbon::parse((string) $slot['starts_at'])->diffInMinutes($preferred, false)));
        }

        if (($draft['availability_strategy'] ?? null) === 'earliest') {
            $slots = $slots->sortBy('starts_at');
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

    private function slotSearchStart(array $draft, string $timezone): Carbon
    {
        if (! empty($draft['preferred_date_start'])) {
            return Carbon::parse((string) $draft['preferred_date_start'], $timezone)->startOfDay();
        }

        return Carbon::parse((string) $draft['preferred_date'], $timezone)->startOfDay();
    }

    private function slotSearchEnd(array $draft, string $timezone): Carbon
    {
        if (! empty($draft['preferred_date_end'])) {
            return Carbon::parse((string) $draft['preferred_date_end'], $timezone)->endOfDay();
        }

        return Carbon::parse((string) $draft['preferred_date'], $timezone)->endOfDay();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposeAlternativeSlots(User $tenant, array $draft): array
    {
        $timezone = $this->availabilityService->timezoneForAccount($tenant);

        $samePeriodDraft = $draft;
        unset($samePeriodDraft['preferred_time'], $samePeriodDraft['preferred_time_start'], $samePeriodDraft['preferred_time_end'], $samePeriodDraft['preferred_time_label']);
        $samePeriodSlots = $this->proposeSlots($tenant, $samePeriodDraft);
        if ($samePeriodSlots !== []) {
            return $samePeriodSlots;
        }

        $start = $this->slotSearchEnd($draft, $timezone)->copy()->addDay()->startOfDay();
        $futureDraft = $samePeriodDraft;
        unset($futureDraft['preferred_date']);
        $futureDraft['preferred_date_start'] = $start->toDateString();
        $futureDraft['preferred_date_end'] = $start->copy()->addDays(14)->toDateString();
        $futureDraft['preferred_date_label'] = 'next_available';

        return $this->proposeSlots($tenant, $futureDraft);
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
        } elseif (Str::contains($text, ['peu importe', 'pas de preference', 'pas de préférence', 'n importe', 'any', 'earliest', 'soonest', 'premier disponible'])) {
            $selectedIndex = 1;
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
            'service_address' => $draft['service_address'] ?? null,
            'notes' => $draft['notes'] ?? null,
        ];

        if (! $settings->allow_create_reservation) {
            $this->requestHumanReview($conversation, [
                'reason' => 'reservation_disabled',
                'draft' => $draft,
                'selected_slot' => $selectedSlot,
            ], $settings);

            return $this->bookingSummaryBuilder->finalMessage(
                $draft,
                $selectedSlot,
                $language,
                $this->availabilityService->timezoneForAccount(User::query()->find((int) $conversation->tenant_id)),
                false
            );
        }

        if (! $settings->allow_create_prospect) {
            $this->requestHumanReview($conversation, [
                'reason' => 'prospect_creation_disabled',
                'draft' => $draft,
                'selected_slot' => $selectedSlot,
            ], $settings);

            return $this->bookingSummaryBuilder->finalMessage(
                $draft,
                $selectedSlot,
                $language,
                $this->availabilityService->timezoneForAccount(User::query()->find((int) $conversation->tenant_id)),
                false
            );
        }

        $pending = (bool) $settings->require_human_validation;
        $prospectAction = $this->actionExecutor->createAction($conversation, AiAction::TYPE_CREATE_PROSPECT, $payload, $pending);
        $reservationAction = $this->actionExecutor->createAction($conversation, AiAction::TYPE_CREATE_RESERVATION, $payload, $pending);

        if ($pending) {
            $conversation->update(['status' => AiConversation::STATUS_WAITING_HUMAN]);

            return $this->bookingSummaryBuilder->finalMessage(
                $draft,
                $selectedSlot,
                $language,
                $this->availabilityService->timezoneForAccount(User::query()->find((int) $conversation->tenant_id)),
                false
            );
        }

        $reservationAction->refresh();
        if ($reservationAction->status === AiAction::STATUS_EXECUTED) {
            $autoConfirmed = ($reservationAction->output_payload['status'] ?? null) === Reservation::STATUS_CONFIRMED;

            return $this->bookingSummaryBuilder->finalMessage(
                $draft,
                $selectedSlot,
                $language,
                $this->availabilityService->timezoneForAccount(User::query()->find((int) $conversation->tenant_id)),
                $autoConfirmed
            );
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

    private function joinSentences(string ...$parts): string
    {
        $text = collect($parts)
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->implode(' ');

        return $text !== '' ? $text.' ' : '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function updateConversationMetadata(AiConversation $conversation, array $metadata): void
    {
        $conversation->update([
            'metadata' => array_merge($conversation->metadata ?? [], $metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $confirmation
     */
    private function isAwaitingBookingConfirmation(array $confirmation): bool
    {
        return (bool) ($confirmation['summary_shown'] ?? false)
            && (bool) ($confirmation['awaiting_user_confirmation'] ?? false)
            && ! (bool) ($confirmation['confirmed_by_user'] ?? false);
    }

    private function isPositiveConfirmation(string $message): bool
    {
        $text = $this->normalizedText($message);

        return preg_match('/^(oui|yes|ok|okay|parfait|confirme|je confirme|c est bon|cest bon|go)$/u', $text) === 1;
    }

    private function isNegativeConfirmation(string $message): bool
    {
        $text = $this->normalizedText($message);

        return preg_match('/^(non|no|pas encore|attendez|annule|annuler)$/u', $text) === 1
            || Str::contains($text, ['modifier', 'changer', 'pas bon']);
    }

    private function willAutoConfirmReservation(AiAssistantSetting $settings): bool
    {
        return false;
    }

    private function questionFor(string $field, string $language, Collection $services): string
    {
        $isFr = $language === 'fr';

        return match ($field) {
            'service_id' => $isFr
                ? 'Quel service souhaitez-vous réserver? '.$this->availableServicesLine($services, 'fr')
                : 'Which service are you interested in booking? '.$this->availableServicesLine($services, 'en'),
            'contact_name' => $isFr ? 'Quel est votre nom complet?' : 'What is your full name?',
            'contact_phone' => $isFr ? 'Quel numéro de téléphone pouvons-nous utiliser?' : 'What phone number can we use?',
            'contact_email' => $isFr ? 'Quelle est votre adresse email?' : 'What email address should we use?',
            'preferred_date' => $isFr ? 'Pour quelle date souhaitez-vous reserver?' : 'What date would you prefer?',
            default => $isFr ? 'Pouvez-vous me donner un peu plus de details?' : 'Could you share a little more detail?',
        };
    }

    private function warmOpeningFor(
        AiConversation $conversation,
        array $previousDraft,
        array $draft,
        string $message,
        string $language
    ): string {
        $previousName = trim((string) ($previousDraft['contact_name'] ?? ''));
        $name = trim((string) ($draft['contact_name'] ?? ''));

        if ($name !== '' && ($previousName === '' || ($this->isOpeningUserMessage($conversation) && $this->hasOpeningGreeting($message)))) {
            $displayName = $this->displayFirstName($name);

            return $language === 'fr'
                ? "Bonjour {$displayName}, ravi de vous aider. "
                : "Hi {$displayName}, happy to help. ";
        }

        if ($this->isOpeningUserMessage($conversation) && $this->hasOpeningGreeting($message)) {
            return $language === 'fr'
                ? 'Bonjour, ravi de vous aider. '
                : 'Hi, happy to help. ';
        }

        return '';
    }

    private function displayFirstName(string $name): string
    {
        $firstName = preg_split('/\s+/', trim($name))[0] ?? $name;
        $firstName = trim($firstName, " \t\n\r\0\x0B,.;:!?");

        return Str::ucfirst(Str::lower($firstName));
    }

    private function isOpeningUserMessage(AiConversation $conversation): bool
    {
        return $conversation->messages()
            ->where('sender_type', AiMessage::SENDER_USER)
            ->count() <= 1;
    }

    private function hasOpeningGreeting(string $message): bool
    {
        $normalized = Str::ascii(Str::lower(trim($message)));

        return preg_match('/^(bonjour|bonsoir|salut|allo|hello|hi|hey)\b/u', $normalized) === 1;
    }

    private function availableServicesLine(Collection $services, string $language): string
    {
        if ($services->isEmpty()) {
            return '';
        }

        $servicesText = $services
            ->values()
            ->map(fn (Product $service, int $index): string => ($index + 1).'. '.(string) $service->name)
            ->implode('; ');

        return ($language === 'fr' ? 'Services disponibles: ' : 'Available services: ').$servicesText.'.';
    }

    private function withoutDuplicateOpening(string $message, string $warmOpening): string
    {
        if (trim($warmOpening) === '') {
            return $message;
        }

        return trim(preg_replace('/^(Parfait|Perfect)\s+[A-Za-zÀ-ÿ\'’-]+\.\s*/u', '', $message) ?? $message);
    }

    private function summaryLine(array $draft, string $language): string
    {
        return $this->missingInformationMessageBuilder->naturalUnderstanding($draft, $language);
    }

    private function dateLabel(string $label, string $language): string
    {
        return match ($label) {
            'next_week' => $language === 'fr' ? 'la semaine prochaine' : 'next week',
            'end_of_month' => $language === 'fr' ? 'la fin du mois' : 'the end of the month',
            'next_month' => $language === 'fr' ? 'le mois prochain' : 'next month',
            'weekend' => $language === 'fr' ? 'la fin de semaine' : 'the weekend',
            default => $label,
        };
    }

    private function timeLabel(string $label, string $language): string
    {
        return match ($label) {
            'after_work' => $language === 'fr' ? 'apres le travail' : 'after work',
            'evening' => $language === 'fr' ? 'le soir' : 'in the evening',
            'morning' => $language === 'fr' ? 'le matin' : 'in the morning',
            'afternoon' => $language === 'fr' ? 'l apres-midi' : 'in the afternoon',
            default => $label,
        };
    }

    private function looksLikeShortPhoneAttempt(string $message): bool
    {
        $digits = preg_replace('/\D+/', '', $message) ?? '';

        return $digits !== '' && strlen($digits) < 7;
    }

    private function looksLikeEmailAttempt(string $message): bool
    {
        $message = trim($message);

        return $message !== '' && Str::contains($message, ['@', '.']);
    }

    private function looksLikeDateAttempt(string $message): bool
    {
        $message = trim($message);

        return $message !== '' && ! $this->looksLikeShortPhoneAttempt($message) && ! $this->looksLikeEmailAttempt($message);
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function formatSlotProposal(array $slots, string $language, ?Collection $suggestions = null): string
    {
        $isFr = $language === 'fr';
        $lines = collect($slots)
            ->map(function (array $slot): string {
                $label = trim(($slot['date'] ?? '').' '.($slot['time'] ?? ''));
                $member = trim((string) ($slot['team_member_name'] ?? ''));

                return "{$slot['index']}. {$label}".($member !== '' ? " avec {$member}" : '');
            })
            ->implode("\n");

        $message = ($isFr ? "Voici 3 creneaux disponibles:\n" : "Here are 3 available slots:\n")
            .$lines."\n"
            .($isFr ? 'Lequel preferez-vous?' : 'Which one do you prefer?');

        $suggestionText = $this->renderSuggestionMessages($suggestions);

        return trim($message.($suggestionText !== '' ? "\n\n".$suggestionText : ''));
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    private function formatAlternativeSlotProposal(array $slots, string $language): string
    {
        $isFr = $language === 'fr';
        $lines = collect($slots)
            ->map(function (array $slot): string {
                $label = trim(($slot['date'] ?? '').' '.($slot['time'] ?? ''));
                $member = trim((string) ($slot['team_member_name'] ?? ''));

                return "{$slot['index']}. {$label}".($member !== '' ? " avec {$member}" : '');
            })
            ->implode("\n");

        return ($isFr ? "Je n ai pas trouve de disponibilite sur votre preference exacte. Les meilleures alternatives sont:\n" : "I did not find availability for your exact preference. The best alternatives are:\n")
            .$lines."\n"
            .($isFr ? 'Voulez-vous choisir l un de ces creneaux?' : 'Would you like to choose one of these slots?');
    }

    private function renderSuggestionMessages(?Collection $suggestions): string
    {
        if (! $suggestions instanceof Collection || $suggestions->isEmpty()) {
            return '';
        }

        return $suggestions
            ->pluck('message')
            ->map(fn ($message): string => trim((string) $message))
            ->filter()
            ->unique()
            ->implode("\n");
    }

    private function line(string $language, string $key): string
    {
        $fr = [
            'reservation_disabled' => 'Je vais transmettre votre demande à l’équipe pour vérification.',
            'no_slots' => 'Je ne vois pas de créneau disponible pour cette date. Voulez-vous essayer une autre date?',
            'choose_slot' => 'Lequel de ces créneaux préférez-vous?',
            'invalid_phone' => 'Ce numéro semble trop court. Pouvez-vous entrer un numéro de téléphone complet?',
            'invalid_email' => 'Cette adresse email ne semble pas complète. Pouvez-vous entrer une adresse valide?',
            'invalid_date' => 'Je n’ai pas bien compris la date. Vous pouvez écrire par exemple: demain, samedi, ce samedi, la semaine prochaine, fin du mois, ou 2026-05-14.',
            'human_review' => 'Je vais transmettre votre demande à l’équipe. Elle vous répondra dès que possible.',
            'pending_review' => 'C’est noté. Votre demande de réservation a été envoyée à l’équipe pour validation.',
            'reservation_created' => 'C’est noté. Votre demande de réservation a été envoyée à l’équipe.',
        ];
        $en = [
            'reservation_disabled' => 'I will send your request to the team for review.',
            'no_slots' => 'I do not see an available slot for that date. Would you like to try another date?',
            'choose_slot' => 'Which of these slots do you prefer?',
            'invalid_phone' => 'That phone number looks too short. Could you enter a complete phone number?',
            'invalid_email' => 'That email address does not look complete. Could you enter a valid email address?',
            'invalid_date' => 'I did not understand the date. You can write something like tomorrow, Saturday, next week, end of month, or 2026-05-14.',
            'human_review' => 'I will send your request to the team. They will reply as soon as possible.',
            'pending_review' => 'Perfect, I will send this request to the team for review before confirmation.',
            'reservation_created' => 'Perfect, your booking request has been saved. The team will confirm the appointment.',
        ];

        return ($language === 'fr' ? $fr : $en)[$key] ?? (($language === 'fr') ? $fr['human_review'] : $en['human_review']);
    }

    private function matchServiceSelection(string $message, Collection $services, ?string $expectedField): ?Product
    {
        if ($expectedField === 'service_id' && preg_match('/^\s*(\d+)\s*$/', $message, $matches) === 1) {
            return $services->values()->get(((int) $matches[1]) - 1);
        }

        return $this->matchService($message, $services);
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

    private function stripContactDetails(string $message, ?string $phone, ?string $email): string
    {
        $text = trim($message);

        if ($phone) {
            $text = str_replace($phone, ' ', $text);
        }

        if ($email) {
            $text = str_ireplace($email, ' ', $text);
        }

        $text = preg_replace('/\b(?:tel|téléphone|telephone|phone|cell|numero|numéro)\b\s*(?:est|:|-)?\s*$/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:mon|my)\s+(?:tel|téléphone|telephone|phone|cell|numero|numéro)\b.*$/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:email|courriel)\b.*$/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\s+(?:et|and)\s*$/iu', ' ', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B,.;:-");
    }

    private function extractName(string $message): ?string
    {
        $stopBeforeIntent = '(?=(?:\s+(?:et\b|and\b|je\s+ve(?:u|ux|ut)\b|j\s*veux\b|i\s+want\b|pour\b|for\b|reservation\b|réservation\b|rdv\b|rendez\b)|[,.;]|$))';
        $patterns = [
            '/(?:mon\s+nom\s+est|je\s+m[\'’\s]?appell?e?|je\s+m[\'’\s]?apell?e?|j[\'’\s]?m[\'’\s]?appell?e?|moi\s+c[\'’\s]?est|je\s+suis)\s+([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s\'’-]{1,80}?)'.$stopBeforeIntent.'/iu',
            '/(?:my\s+name\s+is|i\s+am|i\'m)\s+([A-Za-z][A-Za-z\s\'-]{1,80}?)(?=(?:\s+(?:and\b|i\s+want\b|for\b|booking\b|appointment\b)|[,.;]|$))/iu',
            '/(?:nom|name)\s*:\s*([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s\'-]{1,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function plainTextName(string $message): ?string
    {
        $text = trim($message);
        if ($text === '' || Str::length($text) > 80) {
            return null;
        }

        if (preg_match('/[\d@]|https?:\/\//iu', $text) === 1) {
            return null;
        }

        if (Str::contains(Str::ascii(Str::lower($text)), ['reservation', 'reserver', 'rendez', 'rdv', 'creneau', 'demain', 'today', 'tomorrow'])) {
            return null;
        }

        if (preg_match('/^[\pL][\pL\s\'-]{1,79}$/u', $text) !== 1) {
            return null;
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function extractAddress(string $message, ?string $expectedField, array $draft): ?string
    {
        $text = trim($message);
        if ($text === '' || ! $this->requiresServiceAddress($draft)) {
            return null;
        }

        $patterns = [
            '/(?:adresse|address)\s*[:\-]?\s*(.{5,160})$/iu',
            '/(?:à|a|au|chez)\s+(\d{1,6}\s+[^.?!]{3,160})$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                return trim($matches[1], " \t\n\r\0\x0B,.;");
            }
        }

        if ($expectedField !== 'service_address') {
            return null;
        }

        if (preg_match('/^\d{1,6}\s+[\pL0-9][\pL0-9\s,.\'’#-]{3,160}$/u', $text) !== 1) {
            return null;
        }

        return trim($text, " \t\n\r\0\x0B,.;");
    }

    /**
     * @return array<string, string>|null
     */
    private function extractDateSelection(string $message, User $tenant): ?array
    {
        $timezone = $this->availabilityService->timezoneForAccount($tenant);
        $now = now($timezone);
        $text = $this->normalizedText($message);

        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $message, $matches) === 1) {
            return $this->singleDate(Carbon::parse($matches[1], $timezone));
        }

        if ($date = $this->extractNumericDate($message, $timezone, $now)) {
            return $this->singleDate($date);
        }

        if ($date = $this->extractNamedMonthDate($text, $timezone, $now)) {
            return $this->singleDate($date);
        }

        if (Str::contains($text, ['apres demain', 'after tomorrow'])) {
            return $this->singleDate($now->copy()->addDays(2));
        }

        if (Str::contains($text, ['demain', 'tomorrow'])) {
            return $this->singleDate($now->copy()->addDay());
        }

        if (Str::contains($text, ['aujourd', 'today'])) {
            return $this->singleDate($now->copy());
        }

        if (preg_match('/\b(?:dans|in)\s+(\d{1,2})\s+(jour|jours|day|days|semaine|semaines|week|weeks)\b/u', $text, $matches) === 1) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            return $this->singleDate(Str::contains($unit, ['semaine', 'week'])
                ? $now->copy()->addWeeks($amount)
                : $now->copy()->addDays($amount));
        }

        $weekday = $this->mentionedWeekday($text);
        if ($weekday !== null && Str::contains($text, ['semaine prochaine', 'prochaine semaine', 'next week'])) {
            return $this->singleDate($this->dateForWeekdayInRange($this->nextWeekRange($now), $weekday));
        }

        if (Str::contains($text, ['semaine prochaine', 'prochaine semaine', 'next week'])) {
            return $this->dateRange($this->nextWeekRange($now), 'next_week');
        }

        if (Str::contains($text, ['fin du mois', 'fin de mois', 'fin mois', 'end of month'])) {
            return $this->dateRange($this->endOfMonthRange($now), 'end_of_month');
        }

        if (Str::contains($text, ['mois prochain', 'next month'])) {
            return $this->dateRange($this->nextMonthRange($now), 'next_month');
        }

        if (Str::contains($text, ['fin de semaine', 'weekend', 'week end'])) {
            return $this->dateRange($this->weekendRange($now), 'weekend');
        }

        if ($weekday !== null) {
            return $this->singleDate($this->nextWeekday($now, $weekday));
        }

        return null;
    }

    private function normalizedText(string $message): string
    {
        $normalized = Str::ascii(Str::lower($message));
        $normalized = str_replace(["'", '’', '-'], ' ', $normalized);

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function extractNumericDate(string $message, string $timezone, Carbon $now): ?Carbon
    {
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](20\d{2}))?\b/', $message, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = isset($matches[3]) ? (int) $matches[3] : (int) $now->year;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = Carbon::create($year, $month, $day, 0, 0, 0, $timezone);
        if (! isset($matches[3]) && $date->lt($now->copy()->startOfDay())) {
            $date->addYear();
        }

        return $date;
    }

    private function extractNamedMonthDate(string $text, string $timezone, Carbon $now): ?Carbon
    {
        $months = [
            'janvier' => 1,
            'january' => 1,
            'fevrier' => 2,
            'february' => 2,
            'mars' => 3,
            'march' => 3,
            'avril' => 4,
            'april' => 4,
            'mai' => 5,
            'may' => 5,
            'juin' => 6,
            'june' => 6,
            'juillet' => 7,
            'july' => 7,
            'aout' => 8,
            'august' => 8,
            'septembre' => 9,
            'september' => 9,
            'octobre' => 10,
            'october' => 10,
            'novembre' => 11,
            'november' => 11,
            'decembre' => 12,
            'december' => 12,
        ];
        $monthPattern = implode('|', array_map(fn (string $month): string => preg_quote($month, '/'), array_keys($months)));

        if (preg_match('/\b(\d{1,2})\s+('.$monthPattern.')(?:\s+(20\d{2}))?\b/u', $text, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = $months[$matches[2]];
        $year = isset($matches[3]) ? (int) $matches[3] : (int) $now->year;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = Carbon::create($year, $month, $day, 0, 0, 0, $timezone);
        if (! isset($matches[3]) && $date->lt($now->copy()->startOfDay())) {
            $date->addYear();
        }

        return $date;
    }

    private function mentionedWeekday(string $text): ?int
    {
        $weekdays = [
            'dimanche' => 0,
            'sunday' => 0,
            'lundi' => 1,
            'monday' => 1,
            'mardi' => 2,
            'tuesday' => 2,
            'mercredi' => 3,
            'wednesday' => 3,
            'jeudi' => 4,
            'thursday' => 4,
            'vendredi' => 5,
            'friday' => 5,
            'samedi' => 6,
            'saturday' => 6,
        ];

        foreach ($weekdays as $weekday => $dayOfWeek) {
            if (preg_match('/\b'.preg_quote($weekday, '/').'\b/u', $text) === 1) {
                return $dayOfWeek;
            }
        }

        return null;
    }

    private function nextWeekday(Carbon $now, int $dayOfWeek): Carbon
    {
        $daysUntil = ($dayOfWeek - (int) $now->dayOfWeek + 7) % 7;
        if ($daysUntil === 0) {
            $daysUntil = 7;
        }

        return $now->copy()->startOfDay()->addDays($daysUntil);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function nextWeekRange(Carbon $now): array
    {
        $daysUntilMonday = (1 - (int) $now->dayOfWeek + 7) % 7;
        if ($daysUntilMonday === 0) {
            $daysUntilMonday = 7;
        }

        $start = $now->copy()->startOfDay()->addDays($daysUntilMonday);

        return [$start, $start->copy()->addDays(6)];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $range
     */
    private function dateForWeekdayInRange(array $range, int $dayOfWeek): Carbon
    {
        [$start] = $range;
        $daysUntil = ($dayOfWeek - (int) $start->dayOfWeek + 7) % 7;

        return $start->copy()->addDays($daysUntil);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function weekendRange(Carbon $now): array
    {
        $start = $this->nextWeekday($now, 6);

        return [$start, $start->copy()->addDay()];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function endOfMonthRange(Carbon $now): array
    {
        $end = $now->copy()->endOfMonth()->startOfDay();
        $start = $end->copy()->subDays(6);

        if ($end->lt($now->copy()->startOfDay())) {
            $end = $now->copy()->addMonthNoOverflow()->endOfMonth()->startOfDay();
            $start = $end->copy()->subDays(6);
        }

        if ($start->lt($now->copy()->startOfDay())) {
            $start = $now->copy()->startOfDay();
        }

        return [$start, $end];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function nextMonthRange(Carbon $now): array
    {
        $start = $now->copy()->addMonthNoOverflow()->startOfMonth()->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @return array<string, string>
     */
    private function singleDate(Carbon $date): array
    {
        return [
            'preferred_date' => $date->toDateString(),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $range
     * @return array<string, string>
     */
    private function dateRange(array $range, string $label): array
    {
        [$start, $end] = $range;

        return [
            'preferred_date_start' => $start->toDateString(),
            'preferred_date_end' => $end->toDateString(),
            'preferred_date_label' => $label,
        ];
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
