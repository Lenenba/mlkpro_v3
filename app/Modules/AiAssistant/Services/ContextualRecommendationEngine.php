<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContextualRecommendationEngine
{
    /**
     * @param  Collection<int, Product>  $services
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function analyze(
        AiConversation $conversation,
        AiAssistantSetting $settings,
        User $tenant,
        Collection $services,
        array $draft,
        string $message,
        string $language
    ): array {
        $text = $this->normalizedText($message);

        $recommendations = [
            'language' => $language,
            'normalized_message' => $text,
            'is_price_question' => $this->containsAny($text, ['prix', 'tarif', 'combien', 'cout', 'coût', 'price', 'cost', 'how much']),
            'is_budget_sensitive' => $this->containsAny($text, ['pas trop cher', 'moins cher', 'budget', 'economique', 'économique', 'cheap', 'affordable']),
            'is_service_exploration' => $this->containsAny($text, ['je ne sais pas', 'pas certain', 'pas sur', 'pas sûr', 'quoi choisir', 'quel service', 'options', 'conseiller', 'guide']),
            'wants_earliest' => $this->containsAny($text, ['plus rapide', 'des que possible', 'dès que possible', 'au plus vite', 'le plus tot', 'le plus tôt', 'as soon as possible', 'soonest', 'earliest']),
            'is_flexible_time' => $this->containsAny($text, ['peu importe', 'n importe', 'nimporte', 'pas de preference', 'pas de préférence', 'any time', 'whenever']),
            'after_work' => $this->containsAny($text, ['apres le travail', 'après le travail', 'apres travail', 'after work']),
            'evening_preference' => $this->containsAny($text, ['soir', 'soiree', 'soirée', 'evening']),
            'morning_preference' => $this->containsAny($text, ['matin', 'morning']),
            'afternoon_preference' => $this->containsAny($text, ['apres midi', 'après midi', 'apres-midi', 'afternoon']),
            'same_as_last_time' => $this->containsAny($text, ['meme service', 'même service', 'derniere fois', 'dernière fois', 'last time', 'same as last']),
            'refund_or_payment_conflict' => $this->containsAny($text, ['rembours', 'refund', 'paiement', 'payment', 'chargeback', 'annuler et rembourse']),
            'service_recommendations' => $this->serviceRecommendations($services, $text),
            'last_service' => null,
        ];

        if ((bool) $settings->enable_client_history_recommendations && $recommendations['same_as_last_time']) {
            $recommendations['last_service'] = $this->lastBookedService($conversation, $tenant);
        }

        return $recommendations;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $recommendations
     * @return array<string, mixed>
     */
    public function applyToDraft(array $draft, array $recommendations): array
    {
        if (($recommendations['wants_earliest'] ?? false) || ($recommendations['is_flexible_time'] ?? false)) {
            $draft['availability_strategy'] = 'earliest';
        }

        if ($recommendations['after_work'] ?? false) {
            $draft['preferred_time_start'] = '17:00';
            $draft['preferred_time_end'] = '20:00';
            $draft['preferred_time_label'] = 'after_work';
        } elseif ($recommendations['evening_preference'] ?? false) {
            $draft['preferred_time_start'] = '17:00';
            $draft['preferred_time_end'] = '21:00';
            $draft['preferred_time_label'] = 'evening';
        } elseif ($recommendations['morning_preference'] ?? false) {
            $draft['preferred_time_start'] = '08:00';
            $draft['preferred_time_end'] = '12:00';
            $draft['preferred_time_label'] = 'morning';
        } elseif ($recommendations['afternoon_preference'] ?? false) {
            $draft['preferred_time_start'] = '12:00';
            $draft['preferred_time_end'] = '17:00';
            $draft['preferred_time_label'] = 'afternoon';
        }

        /** @var Product|null $lastService */
        $lastService = $recommendations['last_service'] ?? null;
        if ($lastService && empty($draft['service_id'])) {
            $draft['suggested_service_id'] = (int) $lastService->id;
            $draft['suggested_service_name'] = (string) $lastService->name;
        }

        return $draft;
    }

    private function normalizedText(string $message): string
    {
        $normalized = Str::ascii(Str::lower($message));
        $normalized = str_replace(["'", '’', '-'], ' ', $normalized);

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /**
     * @param  Collection<int, Product>  $services
     * @return Collection<int, Product>
     */
    private function serviceRecommendations(Collection $services, string $text): Collection
    {
        if ($services->isEmpty()) {
            return collect();
        }

        $needles = [];
        if ($this->containsAny($text, ['cheveux abime', 'cheveux abim', 'cheveux sec', 'cassant', 'reparateur', 'réparateur', 'repair', 'dry hair'])) {
            $needles = ['soin', 'repair', 'repar', 'consult', 'diagnostic', 'traitement'];
        } elseif ($this->containsAny($text, ['coupe', 'haircut', 'rapide'])) {
            $needles = ['coupe', 'haircut', 'quick', 'rapide'];
        } elseif ($this->containsAny($text, ['couleur', 'coloration', 'color'])) {
            $needles = ['color', 'couleur', 'coloration'];
        }

        if ($needles === []) {
            return collect();
        }

        return $services
            ->filter(function (Product $service) use ($needles): bool {
                $haystack = Str::ascii(Str::lower(trim((string) $service->name.' '.(string) $service->description)));

                foreach ($needles as $needle) {
                    if (Str::contains($haystack, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->take(3);
    }

    private function lastBookedService(AiConversation $conversation, User $tenant): ?Product
    {
        $customer = $this->resolveCustomer($conversation, $tenant);
        if (! $customer) {
            return null;
        }

        $reservation = Reservation::query()
            ->where('account_id', (int) $tenant->id)
            ->where('client_id', (int) $customer->id)
            ->whereNotNull('service_id')
            ->latest('starts_at')
            ->first();

        return $reservation?->service()->first(['id', 'name', 'description', 'price', 'currency_code', 'unit']);
    }

    private function resolveCustomer(AiConversation $conversation, User $tenant): ?Customer
    {
        if ($conversation->client_id) {
            return Customer::query()
                ->where('user_id', (int) $tenant->id)
                ->whereKey((int) $conversation->client_id)
                ->first();
        }

        $email = trim((string) $conversation->visitor_email);
        if ($email !== '') {
            return Customer::query()
                ->where('user_id', (int) $tenant->id)
                ->where('email', $email)
                ->first();
        }

        $phone = preg_replace('/\D+/', '', (string) $conversation->visitor_phone) ?? '';
        if ($phone === '') {
            return null;
        }

        return Customer::query()
            ->where('user_id', (int) $tenant->id)
            ->where('phone', 'like', '%'.$phone.'%')
            ->first();
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($haystack, Str::ascii(Str::lower($needle)))) {
                return true;
            }
        }

        return false;
    }
}
