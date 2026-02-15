<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

class LeadServiceSuggestionService
{
    private const CATEGORY_KEYWORDS = [
        'website' => [
            'site',
            'website',
            'vitrine',
            'landing page',
            'portfolio',
            'showcase',
            'wordpress',
        ],
        'booking' => [
            'reservation',
            'booking',
            'appointment',
            'agenda',
            'calendar',
            'planning',
            'rendez vous',
            'rdv',
        ],
        'payment' => [
            'payment',
            'paiement',
            'checkout',
            'stripe',
            'facture',
            'invoice',
            'subscription',
            'abonnement',
        ],
        'crm' => [
            'crm',
            'client',
            'clients',
            'pipeline',
            'lead',
            'prospect',
            'relance',
            'follow up',
        ],
        'integration' => [
            'integration',
            'integrations',
            'api',
            'webhook',
            'connect',
            'sync',
            'automation',
            'automatisation',
        ],
    ];

    private const STOP_WORDS = [
        'a',
        'an',
        'and',
        'avec',
        'besoin',
        'de',
        'des',
        'du',
        'for',
        'have',
        'i',
        'je',
        'la',
        'le',
        'les',
        'mon',
        'my',
        'nous',
        'our',
        'pour',
        'the',
        'un',
        'une',
        'veux',
        'want',
        'with',
    ];

    private const QUOTE_QUESTION_CATALOG = [
        'common' => [
            [
                'id' => 'business_goal',
                'label' => 'Main business goal',
                'hint' => 'What outcome do you expect from this project?',
            ],
            [
                'id' => 'desired_deadline',
                'label' => 'Target deadline',
                'hint' => 'When do you need the first version live?',
            ],
            [
                'id' => 'budget_range',
                'label' => 'Budget range',
                'hint' => 'Provide a budget range or expected envelope.',
            ],
        ],
        'website' => [
            [
                'id' => 'website_pages',
                'label' => 'Number of pages',
                'hint' => 'How many pages should the website include?',
            ],
            [
                'id' => 'brand_assets_ready',
                'label' => 'Brand assets',
                'hint' => 'Do you already have logo, visuals and text content?',
            ],
        ],
        'booking' => [
            [
                'id' => 'booking_volume',
                'label' => 'Booking volume',
                'hint' => 'How many bookings per week/month do you expect?',
            ],
            [
                'id' => 'staff_count',
                'label' => 'Team size',
                'hint' => 'How many team members need booking access?',
            ],
        ],
        'payment' => [
            [
                'id' => 'payment_flows',
                'label' => 'Payment flows',
                'hint' => 'Online, on-site, subscriptions, deposits, partial payments?',
            ],
            [
                'id' => 'payment_provider',
                'label' => 'Payment provider',
                'hint' => 'Preferred provider (Stripe, cash, transfer, other).',
            ],
        ],
        'crm' => [
            [
                'id' => 'crm_contacts_volume',
                'label' => 'Contacts volume',
                'hint' => 'Approximate number of contacts/leads to manage.',
            ],
            [
                'id' => 'crm_pipeline',
                'label' => 'Pipeline stages',
                'hint' => 'Which lead stages do you want to track?',
            ],
        ],
        'integration' => [
            [
                'id' => 'integration_tools',
                'label' => 'Tools to integrate',
                'hint' => 'List the apps/platforms to connect.',
            ],
            [
                'id' => 'integration_api_access',
                'label' => 'API access',
                'hint' => 'Do you already have API keys/docs access?',
            ],
        ],
    ];

    public function intentOptions(): array
    {
        return array_keys(self::CATEGORY_KEYWORDS);
    }

    public function quoteQuestionCatalog(): array
    {
        return self::QUOTE_QUESTION_CATALOG;
    }

    public function quoteQuestionsForTags(array $intentTags): array
    {
        $resolvedTags = $this->sanitizeIntentTags($intentTags);
        $questionMap = collect(self::QUOTE_QUESTION_CATALOG['common'] ?? [])
            ->keyBy('id');

        foreach ($resolvedTags as $tag) {
            foreach (self::QUOTE_QUESTION_CATALOG[$tag] ?? [] as $question) {
                $questionId = (string) ($question['id'] ?? '');
                if ($questionId === '') {
                    continue;
                }
                $questionMap[$questionId] = $question;
            }
        }

        return $questionMap->values()->all();
    }

    public function sanitizeIntentTags(array $tags): array
    {
        $allowed = $this->intentOptions();

        return collect($tags)
            ->map(fn ($tag) => strtolower(trim((string) $tag)))
            ->filter(fn ($tag) => in_array($tag, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    public function sanitizeQualificationAnswers(array $answers, array $intentTags): array
    {
        $allowedQuestionIds = collect($this->quoteQuestionsForTags($intentTags))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();
        $allowedMap = array_flip($allowedQuestionIds);

        return collect($answers)
            ->mapWithKeys(function ($value, $key) use ($allowedMap) {
                $resolvedKey = trim((string) $key);
                if ($resolvedKey === '' || !array_key_exists($resolvedKey, $allowedMap)) {
                    return [];
                }

                $resolvedValue = trim((string) $value);
                if ($resolvedValue === '') {
                    return [];
                }

                return [$resolvedKey => $resolvedValue];
            })
            ->all();
    }

    public function missingQuoteInformation(array $intentTags, array $answers): array
    {
        $questions = $this->quoteQuestionsForTags($intentTags);
        $sanitizedAnswers = $this->sanitizeQualificationAnswers($answers, $intentTags);

        return collect($questions)
            ->filter(function (array $question) use ($sanitizedAnswers) {
                $questionId = (string) ($question['id'] ?? '');
                if ($questionId === '') {
                    return false;
                }

                $answer = trim((string) ($sanitizedAnswers[$questionId] ?? ''));
                return $answer === '';
            })
            ->map(fn (array $question) => [
                'id' => (string) ($question['id'] ?? ''),
                'label' => (string) ($question['label'] ?? ''),
                'hint' => (string) ($question['hint'] ?? ''),
            ])
            ->values()
            ->all();
    }

    public function filterValidServiceIds(User $owner, array $serviceIds): array
    {
        $ids = collect($serviceIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Product::query()
            ->services()
            ->byUser($owner->id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function catalogServices(User $owner, int $limit = 200): array
    {
        $resolvedLimit = max(1, min(500, (int) $limit));

        return Product::query()
            ->services()
            ->byUser($owner->id)
            ->where('is_active', true)
            ->with('category:id,name')
            ->orderBy('name')
            ->limit($resolvedLimit)
            ->get([
                'id',
                'user_id',
                'category_id',
                'name',
                'description',
                'price',
            ])
            ->map(fn (Product $service) => $this->formatSuggestionService($service, 0))
            ->values()
            ->all();
    }

    public function suggest(
        User $owner,
        ?string $serviceType,
        ?string $description,
        array $intentTags = [],
        int $limit = 8
    ): array {
        $resolvedLimit = max(1, min(20, (int) $limit));
        $normalizedTags = $this->sanitizeIntentTags($intentTags);
        $text = $this->normalizeText(trim((string) $serviceType . ' ' . (string) $description));

        $services = Product::query()
            ->services()
            ->byUser($owner->id)
            ->where('is_active', true)
            ->with('category:id,name')
            ->get([
                'id',
                'user_id',
                'category_id',
                'name',
                'description',
                'price',
            ]);

        if ($services->isEmpty()) {
            return [
                'detected_categories' => [],
                'suggestions' => [],
            ];
        }

        if ($text === '' && empty($normalizedTags)) {
            return [
                'detected_categories' => [],
                'suggestions' => $services
                    ->take($resolvedLimit)
                    ->map(fn (Product $service) => $this->formatSuggestionService($service, 0))
                    ->values()
                    ->all(),
            ];
        }

        $detectedCategories = $this->detectCategories($text, $normalizedTags);
        $tokens = $this->tokensFromText($text);

        $ranked = $services
            ->map(function (Product $service) use ($tokens, $detectedCategories, $text) {
                $score = $this->scoreService($service, $tokens, $detectedCategories, $text);
                if ($score <= 0) {
                    return null;
                }

                return [
                    'score' => $score,
                    'service' => $service,
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take($resolvedLimit)
            ->values();

        if ($ranked->isEmpty()) {
            $ranked = $services
                ->take($resolvedLimit)
                ->map(fn (Product $service) => [
                    'score' => 0,
                    'service' => $service,
                ])
                ->values();
        }

        return [
            'detected_categories' => collect($detectedCategories)
                ->map(fn ($score, $id) => ['id' => $id, 'score' => $score])
                ->sortByDesc('score')
                ->values()
                ->all(),
            'suggestions' => $ranked
                ->map(fn (array $row) => $this->formatSuggestionService($row['service'], (int) $row['score']))
                ->values()
                ->all(),
        ];
    }

    private function formatSuggestionService(Product $service, int $score): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'price' => $service->price !== null ? (float) $service->price : null,
            'category_id' => $service->category_id,
            'category_name' => $service->category?->name,
            'score' => (int) $score,
        ];
    }

    /**
     * @param array<string> $tokens
     * @param array<string, int> $detectedCategories
     */
    private function scoreService(Product $service, array $tokens, array $detectedCategories, string $text): int
    {
        $haystack = $this->normalizeText(
            implode(' ', [
                (string) $service->name,
                (string) $service->description,
                (string) ($service->category?->name ?? ''),
            ])
        );
        if ($haystack === '') {
            return 0;
        }

        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $score += 2;
            }
        }

        if ($text !== '' && strlen($text) >= 4 && str_contains($haystack, $text)) {
            $score += 4;
        }

        foreach ($detectedCategories as $categoryId => $weight) {
            if ($this->matchesCategory($haystack, $categoryId)) {
                $score += $weight * 3;
            }
        }

        return $score;
    }

    /**
     * @return array<string, int>
     */
    private function detectCategories(string $text, array $intentTags): array
    {
        $detected = [];

        foreach ($intentTags as $tag) {
            $detected[$tag] = 4;
        }

        if ($text === '') {
            return $detected;
        }

        foreach (self::CATEGORY_KEYWORDS as $categoryId => $keywords) {
            $matches = 0;
            foreach ($keywords as $keyword) {
                $normalizedKeyword = $this->normalizeText($keyword);
                if ($normalizedKeyword !== '' && str_contains($text, $normalizedKeyword)) {
                    $matches++;
                }
            }

            if ($matches > 0) {
                $detected[$categoryId] = max($detected[$categoryId] ?? 0, min(4, 1 + $matches));
            }
        }

        arsort($detected);

        return $detected;
    }

    private function matchesCategory(string $haystack, string $categoryId): bool
    {
        foreach (self::CATEGORY_KEYWORDS[$categoryId] ?? [] as $keyword) {
            $normalizedKeyword = $this->normalizeText($keyword);
            if ($normalizedKeyword !== '' && str_contains($haystack, $normalizedKeyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function tokensFromText(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return collect(explode(' ', $text))
            ->map(fn ($token) => trim($token))
            ->filter(fn ($token) => strlen($token) >= 3)
            ->reject(fn ($token) => in_array($token, self::STOP_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeText(?string $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
