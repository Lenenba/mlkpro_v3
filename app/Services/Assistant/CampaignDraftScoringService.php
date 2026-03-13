<?php

namespace App\Services\Assistant;

use App\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CampaignDraftScoringService
{
    /**
     * @param  array<int, string>  $enabledChannels
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $performance
     * @return array<int, array<string, mixed>>
     */
    public function rankChannels(array $enabledChannels, array $brief, string $campaignType, array $performance = []): array
    {
        $timing = Str::lower(Str::ascii((string) ($brief['timing_hint'] ?? '')));
        $priority = match ($campaignType) {
            Campaign::TYPE_NEW_OFFER, Campaign::TYPE_BACK_AVAILABLE, Campaign::TYPE_ANNOUNCEMENT => [
                Campaign::CHANNEL_EMAIL,
                Campaign::CHANNEL_IN_APP,
                Campaign::CHANNEL_SMS,
            ],
            Campaign::TYPE_WINBACK => [
                Campaign::CHANNEL_EMAIL,
                Campaign::CHANNEL_SMS,
                Campaign::CHANNEL_IN_APP,
            ],
            default => [
                Campaign::CHANNEL_EMAIL,
                Campaign::CHANNEL_SMS,
                Campaign::CHANNEL_IN_APP,
            ],
        };

        if ($this->containsAny($timing, ['weekend', 'week end', 'week-end', 'today', 'aujourd', 'tomorrow', 'demain', 'urgent'])) {
            $priority = [
                Campaign::CHANNEL_SMS,
                Campaign::CHANNEL_EMAIL,
                Campaign::CHANNEL_IN_APP,
            ];
        }

        $ranked = collect($enabledChannels)
            ->map(function (string $channel) use ($priority, $campaignType, $performance): array {
                $score = 0.0;
                $reasons = [];

                $priorityIndex = array_search($channel, $priority, true);
                $score += $priorityIndex === false ? 20.0 : max(0.0, 42.0 - ($priorityIndex * 8.0));
                $reasons[] = 'heuristic_priority';

                $typedHistory = $this->findChannelHistory($performance, $campaignType, $channel);
                $globalHistory = $this->findChannelHistory($performance, null, $channel);

                if ($typedHistory) {
                    $score += 18.0 + min(40.0, (float) ($typedHistory['score'] ?? 0.0));
                    $reasons[] = 'historical_type_performance';
                } elseif ($globalHistory) {
                    $score += 8.0 + min(24.0, ((float) ($globalHistory['score'] ?? 0.0) * 0.65));
                    $reasons[] = 'historical_global_performance';
                }

                return [
                    'channel' => $channel,
                    'score' => round($score, 2),
                    'reasons' => $reasons,
                    'metrics' => $typedHistory ?? $globalHistory,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->all();

        return $ranked;
    }

    /**
     * @param  array<int, array<string, mixed>>  $offers
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $performance
     * @return array<int, array<string, mixed>>
     */
    public function rankOffers(array $offers, array $brief, string $campaignType, array $performance = []): array
    {
        $offerHint = Str::lower(Str::ascii((string) ($brief['offer_hint'] ?? '')));

        return collect(array_values($offers))
            ->map(function (array $offer, int $index) use ($offerHint, $campaignType, $performance): array {
                $score = max(0.0, 28.0 - ($index * 3.0));
                $reasons = ['recency'];

                if ($offerHint !== '') {
                    $similarity = $this->scoreOfferHint($offerHint, $offer);
                    if ($similarity > 0.0) {
                        $score += round($similarity * 80.0, 2);
                        $reasons[] = 'offer_hint_match';
                    }
                }

                $promoPercent = (float) ($offer['promo_discount_percent'] ?? 0.0);
                if (in_array($campaignType, [Campaign::TYPE_PROMOTION, Campaign::TYPE_BACK_AVAILABLE], true) && $promoPercent > 0.0) {
                    $score += min(18.0, $promoPercent);
                    $reasons[] = 'promotion_discount';
                }

                if ($campaignType === Campaign::TYPE_NEW_OFFER) {
                    $score += max(0.0, 10.0 - ($index * 1.5));
                    $reasons[] = 'launch_recency';
                }

                $typedHistory = $this->findOfferHistory(
                    $performance,
                    $campaignType,
                    (int) ($offer['id'] ?? 0),
                    (string) ($offer['offer_type'] ?? 'product')
                );
                $globalHistory = $this->findOfferHistory(
                    $performance,
                    null,
                    (int) ($offer['id'] ?? 0),
                    (string) ($offer['offer_type'] ?? 'product')
                );

                if ($typedHistory) {
                    $score += 16.0 + min(45.0, (float) ($typedHistory['score'] ?? 0.0));
                    $reasons[] = 'historical_offer_performance';
                } elseif ($globalHistory) {
                    $score += 8.0 + min(22.0, ((float) ($globalHistory['score'] ?? 0.0) * 0.65));
                    $reasons[] = 'historical_offer_performance_global';
                }

                return array_merge($offer, [
                    'score' => round($score, 2),
                    'score_reasons' => array_values(array_unique($reasons)),
                    'history_metrics' => $typedHistory ?? $globalHistory,
                ]);
            })
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     * @param  array<string, mixed>  $performance
     * @return array<int, array<string, mixed>>
     */
    public function rankTemplates(
        array $templates,
        string $channel,
        string $campaignType,
        string $locale,
        array $performance = []
    ): array {
        $channel = strtoupper(trim($channel));
        $campaignType = strtoupper(trim($campaignType));
        $locale = strtoupper(trim($locale));

        return collect(array_values($templates))
            ->map(function (array $template) use ($channel, $campaignType, $locale, $performance): array {
                $templateChannel = strtoupper(trim((string) ($template['channel'] ?? '')));
                $templateType = strtoupper(trim((string) ($template['campaign_type'] ?? '')));
                $templateLocale = strtoupper(trim((string) ($template['language'] ?? '')));

                $score = $templateChannel === $channel ? 12.0 : 0.0;
                $reasons = ['default_template'];

                if ($templateType === $campaignType && $templateLocale === $locale) {
                    $score += 34.0;
                    $reasons[] = 'exact_campaign_type_and_locale';
                } elseif ($templateType === $campaignType) {
                    $score += 26.0;
                    $reasons[] = 'exact_campaign_type';
                } elseif ($templateLocale === $locale) {
                    $score += 18.0;
                    $reasons[] = 'exact_locale';
                } elseif ($templateType === '' && $templateLocale === '') {
                    $score += 8.0;
                    $reasons[] = 'generic_fallback';
                }

                $typedHistory = $this->findTemplateHistory(
                    $performance,
                    $campaignType,
                    (int) ($template['id'] ?? 0),
                    $channel
                );
                $globalHistory = $this->findTemplateHistory(
                    $performance,
                    null,
                    (int) ($template['id'] ?? 0),
                    $channel
                );

                if ($typedHistory) {
                    $score += 12.0 + min(45.0, (float) ($typedHistory['score'] ?? 0.0));
                    $reasons[] = 'historical_template_performance';
                } elseif ($globalHistory) {
                    $score += 6.0 + min(22.0, ((float) ($globalHistory['score'] ?? 0.0) * 0.65));
                    $reasons[] = 'historical_template_performance_global';
                }

                if (! empty($template['updated_at'])) {
                    $updatedAt = Carbon::parse((string) $template['updated_at']);
                    $score += max(0.0, 6.0 - min(6.0, $updatedAt->diffInDays(now()) / 7));
                    $reasons[] = 'recent_template';
                }

                return array_merge($template, [
                    'score' => round($score, 2),
                    'score_reasons' => array_values(array_unique($reasons)),
                    'history_metrics' => $typedHistory ?? $globalHistory,
                ]);
            })
            ->sort(function (array $left, array $right): int {
                $scoreOrder = ($right['score'] ?? 0) <=> ($left['score'] ?? 0);
                if ($scoreOrder !== 0) {
                    return $scoreOrder;
                }

                return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $performance
     * @return array<string, mixed>|null
     */
    private function findChannelHistory(array $performance, ?string $campaignType, string $channel): ?array
    {
        $rows = $campaignType
            ? data_get($performance, 'channel_recommendations.by_type.'.strtoupper($campaignType), [])
            : data_get($performance, 'channel_recommendations.global', []);

        foreach ($rows as $row) {
            if (strtoupper((string) ($row['channel'] ?? '')) === strtoupper($channel)) {
                return is_array($row) ? $row : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $performance
     * @return array<string, mixed>|null
     */
    private function findOfferHistory(array $performance, ?string $campaignType, int $offerId, string $offerType): ?array
    {
        $rows = $campaignType
            ? data_get($performance, 'offer_recommendations.by_type.'.strtoupper($campaignType), [])
            : data_get($performance, 'offer_recommendations.global', []);

        foreach ($rows as $row) {
            if ((int) ($row['offer_id'] ?? 0) !== $offerId) {
                continue;
            }

            if (strtolower((string) ($row['offer_type'] ?? 'product')) !== strtolower($offerType)) {
                continue;
            }

            return is_array($row) ? $row : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $performance
     * @return array<string, mixed>|null
     */
    private function findTemplateHistory(array $performance, ?string $campaignType, int $templateId, string $channel): ?array
    {
        $rows = $campaignType
            ? data_get($performance, 'template_recommendations.by_type.'.strtoupper($campaignType), [])
            : data_get($performance, 'template_recommendations.global', []);

        foreach ($rows as $row) {
            if ((int) ($row['template_id'] ?? 0) !== $templateId) {
                continue;
            }

            if (strtoupper((string) ($row['channel'] ?? '')) !== strtoupper($channel)) {
                continue;
            }

            return is_array($row) ? $row : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function scoreOfferHint(string $offerHint, array $offer): float
    {
        $parts = [
            Str::lower(Str::ascii((string) ($offer['name'] ?? ''))),
            Str::lower(Str::ascii((string) ($offer['category_name'] ?? ''))),
            ...collect($offer['tags'] ?? [])->map(fn ($value) => Str::lower(Str::ascii((string) $value)))->all(),
        ];

        $best = 0.0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            similar_text($offerHint, $part, $percent);
            $score = round($percent / 100, 4);

            if (str_contains($part, $offerHint) || str_contains($offerHint, $part)) {
                $score = max($score, 0.92);
            }

            $best = max($best, $score);
        }

        return $best;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
