<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Product;
use App\Models\User;
use App\Modules\AiAssistant\DTO\ProactiveSuggestion;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Support\Collection;

class ProactiveSuggestionEngine
{
    /**
     * @param  Collection<int, Product>  $services
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $recommendations
     * @param  array<int, array<string, mixed>>  $slots
     * @return Collection<int, ProactiveSuggestion>
     */
    public function suggestions(
        AiConversation $conversation,
        AiAssistantSetting $settings,
        User $tenant,
        Collection $services,
        array $draft,
        array $recommendations,
        string $language,
        array $slots = [],
        ?string $missing = null
    ): Collection {
        if (! (bool) $settings->enable_proactive_suggestions) {
            return collect();
        }

        $suggestions = collect();

        if ($recommendations['refund_or_payment_conflict'] ?? false) {
            $suggestions->push($this->humanReviewSuggestion($language));
        }

        if ($missing && $missing !== 'service_id') {
            $suggestions->push(new ProactiveSuggestion(
                type: ProactiveSuggestion::TYPE_MISSING_INFORMATION_HINT,
                priority: 40,
                label: $language === 'fr' ? 'Info manquante' : 'Missing info',
                message: $this->missingInformationMessage($missing, $language),
                actionType: 'ask_'.$missing,
                payload: ['missing' => $missing],
                confidenceScore: 0.8,
            ));
        }

        if ($missing === 'service_id' && (bool) $settings->allow_ai_to_recommend_services) {
            /** @var Collection<int, Product> $recommendedServices */
            $recommendedServices = $recommendations['service_recommendations'] ?? collect();
            if ($recommendedServices->isNotEmpty()) {
                $suggestions->push(new ProactiveSuggestion(
                    type: ProactiveSuggestion::TYPE_SERVICE_RECOMMENDATION,
                    priority: 95,
                    label: $language === 'fr' ? 'Services utiles' : 'Helpful services',
                    message: $this->serviceRecommendationMessage($recommendedServices, $language),
                    actionType: 'recommend_services',
                    payload: [
                        'service_ids' => $recommendedServices->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    ],
                    confidenceScore: 0.78,
                ));
            }
        }

        /** @var Product|null $lastService */
        $lastService = $recommendations['last_service'] ?? null;
        if ($lastService) {
            $suggestions->push(new ProactiveSuggestion(
                type: ProactiveSuggestion::TYPE_SERVICE_RECOMMENDATION,
                priority: 98,
                label: $language === 'fr' ? 'Dernier service' : 'Last service',
                message: $language === 'fr'
                    ? "Je vois que votre dernier rendez-vous etait pour {$lastService->name}. Voulez-vous reserver le meme service?"
                    : "I see your last appointment was for {$lastService->name}. Would you like to book the same service?",
                actionType: 'confirm_last_service',
                payload: ['service_id' => (int) $lastService->id],
                confidenceScore: 0.86,
            ));
        }

        if ($slots !== []) {
            if (($recommendations['wants_earliest'] ?? false) || ($recommendations['is_flexible_time'] ?? false)) {
                if ((bool) $settings->allow_ai_to_choose_earliest_slot) {
                    $suggestions->push(new ProactiveSuggestion(
                        type: ProactiveSuggestion::TYPE_EARLIEST_SLOT_RECOMMENDATION,
                        priority: 90,
                        label: $language === 'fr' ? 'Premier creneau' : 'Earliest slot',
                        message: $this->earliestSlotMessage($slots[0], $language),
                        actionType: 'recommend_slot',
                        payload: ['slot' => $slots[0]],
                        confidenceScore: 0.84,
                    ));
                }
            }

            $staffNames = collect($slots)
                ->pluck('team_member_name')
                ->map(fn ($name): string => trim((string) $name))
                ->filter()
                ->unique()
                ->values();

            if ((bool) $settings->allow_ai_to_recommend_staff && $staffNames->count() > 1) {
                $suggestions->push(new ProactiveSuggestion(
                    type: ProactiveSuggestion::TYPE_STAFF_RECOMMENDATION,
                    priority: 55,
                    label: $language === 'fr' ? 'Equipe disponible' : 'Available staff',
                    message: $language === 'fr'
                        ? 'Plusieurs personnes sont disponibles: '.$staffNames->implode(', ').'. Si vous n avez pas de preference, je peux garder le premier creneau disponible.'
                        : 'Several team members are available: '.$staffNames->implode(', ').'. If you do not have a preference, I can keep the first available slot.',
                    actionType: 'recommend_staff',
                    payload: ['staff_names' => $staffNames->all()],
                    confidenceScore: 0.72,
                ));
            }

            if ((bool) $settings->enable_upsell_suggestions) {
                $upsell = $this->upsellService($services, $draft);
                if ($upsell) {
                    $suggestions->push(new ProactiveSuggestion(
                        type: ProactiveSuggestion::TYPE_UPSELL_RECOMMENDATION,
                        priority: 25,
                        label: $language === 'fr' ? 'Option complementaire' : 'Add-on option',
                        message: $language === 'fr'
                            ? "Optionnel: voulez-vous aussi ajouter {$upsell->name}? Je peux verifier avec ou sans cet ajout."
                            : "Optional: would you also like to add {$upsell->name}? I can check with or without it.",
                        actionType: 'suggest_upsell',
                        payload: ['service_id' => (int) $upsell->id],
                        confidenceScore: 0.55,
                    ));
                }
            }
        }

        return $suggestions
            ->sortByDesc('priority')
            ->values()
            ->take(max(1, (int) ($settings->max_suggestions_per_response ?: 3)));
    }

    public function humanReviewSuggestion(string $language): ProactiveSuggestion
    {
        return new ProactiveSuggestion(
            type: ProactiveSuggestion::TYPE_HUMAN_REVIEW_RECOMMENDATION,
            priority: 100,
            label: $language === 'fr' ? 'Validation equipe' : 'Team review',
            message: $language === 'fr'
                ? 'Je peux vous aider pour la demande, mais pour le remboursement ou le paiement je prefere faire valider par l equipe afin d eviter une erreur. Voulez-vous que je leur envoie un resume?'
                : 'I can help with the request, but for refunds or payment issues I prefer to have the team review it to avoid a mistake. Would you like me to send them a summary?',
            actionType: 'request_human_review',
            payload: ['reason' => 'payment_or_refund_conflict'],
            confidenceScore: 0.9,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return Collection<int, ProactiveSuggestion>
     */
    public function alternativeSlotSuggestions(array $slots, string $language, int $limit): Collection
    {
        return collect($slots)
            ->take(max(1, $limit))
            ->values()
            ->map(fn (array $slot, int $index): ProactiveSuggestion => new ProactiveSuggestion(
                type: ProactiveSuggestion::TYPE_ALTERNATIVE_SLOT_RECOMMENDATION,
                priority: 85 - $index,
                label: $language === 'fr' ? 'Alternative' : 'Alternative',
                message: $this->slotLine($slot, $language),
                actionType: 'recommend_alternative_slot',
                payload: ['slot' => $slot],
                confidenceScore: 0.76,
            ));
    }

    private function missingInformationMessage(string $missing, string $language): string
    {
        return match ($missing) {
            'contact_name' => $language === 'fr'
                ? 'Il me manque votre nom complet pour préparer la demande.'
                : 'I need your full name to prepare the request.',
            'contact_phone' => $language === 'fr'
                ? 'Il me manque un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.'
                : 'I need a phone number so the team can confirm.',
            'contact_email' => $language === 'fr'
                ? 'Quelle adresse email pouvons-nous utiliser pour vous envoyer la confirmation?'
                : 'Which email address can we use to send your confirmation?',
            'preferred_date' => $language === 'fr'
                ? 'Il me manque une date ou une période pour chercher les bons créneaux.'
                : 'I need a date or period to search useful slots.',
            default => $language === 'fr'
                ? 'Il me manque juste une precision pour continuer.'
                : 'I just need one more detail to continue.',
        };
    }

    /**
     * @param  Collection<int, Product>  $services
     */
    private function serviceRecommendationMessage(Collection $services, string $language): string
    {
        $names = $services->pluck('name')->map(fn ($name): string => (string) $name)->values();

        return $language === 'fr'
            ? 'Je peux vous orienter vers '.$names->implode(' ou ').'. Voulez-vous que je regarde les disponibilites pour l un de ces services?'
            : 'I can point you toward '.$names->implode(' or ').'. Would you like me to check availability for one of these services?';
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function earliestSlotMessage(array $slot, string $language): string
    {
        return $language === 'fr'
            ? 'Si vous n avez pas de preference, je vous recommande le premier creneau disponible: '.$this->slotLine($slot, $language).'.'
            : 'If you do not have a preference, I recommend the first available slot: '.$this->slotLine($slot, $language).'.';
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function slotLine(array $slot, string $language): string
    {
        $label = trim((string) (($slot['date'] ?? '').' '.($slot['time'] ?? '')));
        $member = trim((string) ($slot['team_member_name'] ?? ''));

        return $label.($member !== '' ? ($language === 'fr' ? " avec {$member}" : " with {$member}") : '');
    }

    /**
     * @param  Collection<int, Product>  $services
     * @param  array<string, mixed>  $draft
     */
    private function upsellService(Collection $services, array $draft): ?Product
    {
        $currentId = (int) ($draft['service_id'] ?? 0);

        return $services
            ->first(function (Product $service) use ($currentId): bool {
                if ((int) $service->id === $currentId) {
                    return false;
                }

                $label = strtolower((string) $service->name.' '.(string) $service->description);

                return str_contains($label, 'soin')
                    || str_contains($label, 'add')
                    || str_contains($label, 'consult')
                    || str_contains($label, 'traitement');
            });
    }
}
