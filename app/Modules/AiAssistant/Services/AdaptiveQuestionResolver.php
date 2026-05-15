<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Product;
use App\Modules\AiAssistant\DTO\ProactiveSuggestion;
use Illuminate\Support\Collection;

class AdaptiveQuestionResolver
{
    /**
     * @param  Collection<int, Product>  $services
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $recommendations
     * @param  Collection<int, ProactiveSuggestion>  $suggestions
     * @return array<string, mixed>
     */
    public function resolve(
        ?string $missing,
        array $draft,
        Collection $services,
        array $recommendations,
        Collection $suggestions,
        string $language
    ): array {
        return [
            'acknowledgement' => $this->acknowledgement($draft, $language),
            'understoodSummary' => $this->understoodSummary($draft, $recommendations, $language),
            'suggestions' => $suggestions->map(fn (ProactiveSuggestion $suggestion): array => $suggestion->toArray())->all(),
            'nextQuestion' => $this->nextQuestion($missing, $draft, $services, $recommendations, $language),
            'action' => $missing ? 'ask_'.$missing : 'continue',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function render(array $plan): string
    {
        $parts = collect([
            trim((string) ($plan['acknowledgement'] ?? '')),
            trim((string) ($plan['understoodSummary'] ?? '')),
        ])->filter()->values();

        $suggestions = collect($plan['suggestions'] ?? [])
            ->pluck('message')
            ->map(fn ($message): string => trim((string) $message))
            ->filter()
            ->values();

        foreach ($suggestions as $suggestion) {
            $parts->push($suggestion);
        }

        $nextQuestion = trim((string) ($plan['nextQuestion'] ?? ''));
        if ($nextQuestion !== '') {
            $parts->push($nextQuestion);
        }

        return $parts->unique()->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function acknowledgement(array $draft, string $language): string
    {
        $name = trim((string) ($draft['contact_name'] ?? ''));
        if ($name !== '') {
            $firstName = preg_split('/\s+/', $name)[0] ?? $name;

            return $language === 'fr' ? "Parfait {$firstName}." : "Perfect {$firstName}.";
        }

        return $language === 'fr' ? 'Bien sur.' : 'Of course.';
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $recommendations
     */
    private function understoodSummary(array $draft, array $recommendations, string $language): string
    {
        $pieces = [];
        if (! empty($draft['service_name'])) {
            $pieces[] = (string) $draft['service_name'];
        } elseif (! empty($draft['suggested_service_name'])) {
            $pieces[] = (string) $draft['suggested_service_name'];
        }

        if (! empty($draft['preferred_date'])) {
            $pieces[] = (string) $draft['preferred_date'];
        } elseif (! empty($draft['preferred_date_label'])) {
            $pieces[] = $this->dateLabel((string) $draft['preferred_date_label'], $language);
        }

        if (! empty($draft['preferred_time_label'])) {
            $pieces[] = $this->timeLabel((string) $draft['preferred_time_label'], $language);
        }

        if ($pieces === []) {
            return '';
        }

        return $language === 'fr'
            ? 'Parfait, je note votre demande pour '.implode(', ', $pieces).'.'
            : 'Perfect, I have your request for '.implode(', ', $pieces).'.';
    }

    /**
     * @param  Collection<int, Product>  $services
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $recommendations
     */
    private function nextQuestion(?string $missing, array $draft, Collection $services, array $recommendations, string $language): string
    {
        if (! $missing) {
            return '';
        }

        if ($missing === 'service_id') {
            if (($recommendations['is_price_question'] ?? false) && $services->isNotEmpty()) {
                return $this->priceAnswer($services, $language);
            }

            if ($recommendations['is_service_exploration'] ?? false) {
                return $language === 'fr'
                    ? 'Pas de souci. Est-ce plutot pour une coupe, une coloration, un soin, ou une consultation?'
                    : 'No problem. Is it more for a haircut, color, treatment, or consultation?';
            }

            if (($recommendations['wants_earliest'] ?? false) || ($recommendations['is_flexible_time'] ?? false)) {
                return $language === 'fr'
                    ? 'Je vais chercher les premiers creneaux disponibles. Quel service souhaitez-vous reserver? '.$this->availableServicesLine($services, $language)
                    : 'I will look for the earliest available slots. Which service would you like to book? '.$this->availableServicesLine($services, $language);
            }

            return $language === 'fr'
                ? 'Pour vous proposer les bons créneaux, quel service souhaitez-vous réserver? '.$this->availableServicesLine($services, $language)
                : 'To suggest the right slots. Which service are you interested in booking? '.$this->availableServicesLine($services, $language);
        }

        if ($missing === 'contact_name') {
            if (empty($draft['contact_phone']) && empty($draft['contact_email'])) {
                return $language === 'fr'
                    ? 'Pour préparer la demande, j’ai besoin de votre nom complet et d’un numéro de téléphone.'
                    : 'What name and phone number can we use for the booking?';
            }

            return $language === 'fr' ? 'Il me manque seulement votre nom complet pour préparer la demande.' : 'What full name can we use?';
        }

        if ($missing === 'contact_phone') {
            if (! empty($draft['contact_email'])) {
                return $language === 'fr'
                    ? 'Merci. Il me manque seulement un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.'
                    : 'Thanks. Do you also have a phone number to make confirmation easier?';
            }

            return $language === 'fr'
                ? 'Il me manque seulement un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.'
                : 'What phone number can we use to confirm?';
        }

        if ($missing === 'contact_email') {
            if (! empty($draft['contact_phone'])) {
                return '';
            }

            return $language === 'fr'
                ? 'Merci. Quelle adresse email pouvons-nous utiliser pour vous envoyer la confirmation?'
                : 'What email address can we use for confirmation?';
        }

        if ($missing === 'preferred_date') {
            if (($recommendations['wants_earliest'] ?? false) || ($recommendations['is_flexible_time'] ?? false)) {
                return $language === 'fr'
                    ? 'Préférez-vous le plus tôt possible, cette semaine, ou une date précise?'
                    : 'Would you prefer the earliest possible time, this week, or a specific date?';
            }

            return $language === 'fr'
                ? 'Pour quelle date ou période souhaitez-vous réserver? Vous pouvez dire par exemple demain, cette semaine, la fin du mois, ou une date précise.'
                : 'Would you prefer today, tomorrow, this week, or a specific date?';
        }

        return $language === 'fr'
            ? 'Pouvez-vous me donner une petite precision pour continuer?'
            : 'Could you share one more detail so I can continue?';
    }

    /**
     * @param  Collection<int, Product>  $services
     */
    private function availableServicesLine(Collection $services, string $language): string
    {
        if ($services->isEmpty()) {
            return '';
        }

        $servicesText = $services
            ->values()
            ->map(fn (Product $service, int $index): string => ($index + 1).'. '.(string) $service->name)
            ->implode('; ');

        return ($language === 'fr' ? 'Options: ' : 'Options: ').$servicesText.'.';
    }

    /**
     * @param  Collection<int, Product>  $services
     */
    private function priceAnswer(Collection $services, string $language): string
    {
        $pricedServices = $services
            ->filter(fn (Product $service): bool => (float) ($service->price ?? 0) > 0)
            ->values();

        if ($pricedServices->isEmpty()) {
            return $language === 'fr'
                ? 'Je peux vous orienter selon le type de service, mais je préfère faire confirmer les prix exacts par l’équipe. Quel service souhaitez-vous réserver?'
                : 'I can guide you by service type, but I prefer to have the team confirm exact prices. Which service are you interested in?';
        }

        $lines = $pricedServices
            ->take(3)
            ->map(fn (Product $service): string => (string) $service->name.' - '.number_format((float) $service->price, 2).' '.(string) ($service->currency_code ?: ''))
            ->implode('; ');

        return $language === 'fr'
            ? "Voici les prix que je vois: {$lines}. Quel service souhaitez-vous reserver?"
            : "Here are the prices I see: {$lines}. Which service would you like to book?";
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
}
