<?php

namespace App\Services;

use App\Models\PlanScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PlanScanAiPipelineService
{
    private const CACHE_PREFIX = 'plan-scan-ai';

    public function __construct(private readonly PlanScanAiExtractor $extractor) {}

    /**
     * @param  array<string, mixed>  $manualMetrics
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(PlanScan $scan, array $manualMetrics = [], array $options = []): array
    {
        $cacheKey = $this->buildCacheKey($scan, $manualMetrics);

        if ($cacheKey !== null && $this->shouldUseCache($options)) {
            $cached = $this->resolveCached($scan, $cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $attempts = [];

        $primaryExtraction = $this->extractor->extract($scan, $manualMetrics, $options);
        $attempts[] = $this->formatAttempt('primary', $primaryExtraction, $options);

        if ($this->shouldAttemptFallback($primaryExtraction, $options)) {
            $fallbackOptions = $this->fallbackOptions($options);
            $fallbackExtraction = $this->extractor->extract($scan, $manualMetrics, $fallbackOptions);
            $attempts[] = $this->formatAttempt('fallback', $fallbackExtraction, $fallbackOptions);
        }

        $this->applyEstimatedCosts($attempts);

        $finalAttempt = $this->selectBestAttempt($attempts);
        $finalExtraction = is_array($finalAttempt['extraction'] ?? null) ? $finalAttempt['extraction'] : [];
        $aggregateUsage = $this->aggregateUsage($attempts);
        $estimatedCost = $this->aggregateEstimatedCost($attempts);
        $publicAttempts = $this->publicAttempts($attempts);

        $result = [
            'status' => $finalExtraction['status'] ?? 'failed',
            'model' => $finalExtraction['model'] ?? null,
            'usage' => $aggregateUsage,
            'raw' => $this->decorateRawPayload($finalExtraction, $publicAttempts, $cacheKey, false, null, $estimatedCost),
            'normalized' => is_array($finalExtraction['normalized'] ?? null) ? $finalExtraction['normalized'] : [],
            'review_required' => (bool) ($finalExtraction['review_required'] ?? true),
            'error_message' => $finalExtraction['error_message'] ?? null,
            'attempts' => $publicAttempts,
            'cache_key' => $cacheKey,
            'cache_hit' => false,
            'cache_source' => null,
            'estimated_cost_usd' => $estimatedCost,
        ];

        if ($cacheKey !== null && $this->canCache($result)) {
            $this->storeRuntimeCache($cacheKey, $result);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $manualMetrics
     */
    public function buildCacheKey(PlanScan $scan, array $manualMetrics = []): ?string
    {
        $fileHash = $scan->plan_file_sha256 ?: $this->resolveFileHash($scan);
        if ($fileHash === null) {
            return null;
        }

        $payload = [
            'user_id' => (int) $scan->user_id,
            'file_hash' => $fileHash,
            'trade_type' => (string) ($scan->trade_type ?: 'general'),
            'surface_m2' => $this->normalizeNullableFloat($manualMetrics['surface_m2'] ?? null),
            'rooms' => $this->normalizeNullableInt($manualMetrics['rooms'] ?? null),
            'priority' => (string) ($manualMetrics['priority'] ?? 'balanced'),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function resolveCached(PlanScan $scan, string $cacheKey): ?array
    {
        $runtimeCachePayload = Cache::get($this->runtimeCacheKey($cacheKey));
        if (is_array($runtimeCachePayload)) {
            return $this->cachedResult($runtimeCachePayload, $cacheKey, 'runtime_cache');
        }

        $ttlMinutes = max(0, (int) config('services.openai.plan_scan_cache_ttl', 1440));
        $cachedScan = PlanScan::query()
            ->where('user_id', $scan->user_id)
            ->where('id', '!=', $scan->id)
            ->where('status', PlanScanService::STATUS_READY)
            ->where('ai_status', 'completed')
            ->where('ai_cache_key', $cacheKey)
            ->when($ttlMinutes > 0, function ($query) use ($ttlMinutes) {
                $query->where('analyzed_at', '>=', now()->subMinutes($ttlMinutes));
            })
            ->latest('analyzed_at')
            ->first();

        if (! $cachedScan) {
            return null;
        }

        return $this->cachedResult([
            'status' => $cachedScan->ai_status,
            'model' => $cachedScan->ai_model,
            'raw' => $cachedScan->ai_extraction_raw,
            'normalized' => $cachedScan->ai_extraction_normalized,
            'review_required' => (bool) $cachedScan->ai_review_required,
            'error_message' => $cachedScan->ai_error_message,
            'attempts' => is_array($cachedScan->ai_attempts) ? $cachedScan->ai_attempts : [],
        ], $cacheKey, 'scan:'.$cachedScan->id);
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    private function cachedResult(array $cached, string $cacheKey, string $cacheSource): array
    {
        $normalized = is_array($cached['normalized'] ?? null) ? $cached['normalized'] : [];
        $attempt = [
            'source' => 'cache',
            'requested_model' => $cached['model'] ?? null,
            'model' => $cached['model'] ?? null,
            'status' => $cached['status'] ?? 'completed',
            'review_required' => (bool) ($cached['review_required'] ?? false),
            'recommended_action' => data_get($normalized, 'recommended_action'),
            'overall_confidence' => (int) data_get($normalized, 'confidence.overall', 0),
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
            'estimated_cost_usd' => 0.0,
            'cache_hit' => true,
            'cache_source' => $cacheSource,
        ];

        return [
            'status' => $cached['status'] ?? 'completed',
            'model' => $cached['model'] ?? null,
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'attempt_count' => 0,
                'models_tried' => [],
                'cache_hit' => true,
            ],
            'raw' => $this->decorateRawPayload(
                is_array($cached['raw'] ?? null) ? $cached['raw'] : [],
                [$attempt],
                $cacheKey,
                true,
                $cacheSource,
                0.0
            ),
            'normalized' => $normalized,
            'review_required' => (bool) ($cached['review_required'] ?? false),
            'error_message' => $cached['error_message'] ?? null,
            'attempts' => [$attempt],
            'cache_key' => $cacheKey,
            'cache_hit' => true,
            'cache_source' => $cacheSource,
            'estimated_cost_usd' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function formatAttempt(string $source, array $extraction, array $options): array
    {
        $normalized = is_array($extraction['normalized'] ?? null) ? $extraction['normalized'] : [];
        $usage = is_array($extraction['usage'] ?? null) ? $extraction['usage'] : [];
        $requestedModel = $this->requestedModel($options);

        return [
            'source' => $source,
            'requested_model' => $requestedModel,
            'model' => $extraction['model'] ?? $requestedModel,
            'status' => $extraction['status'] ?? 'failed',
            'review_required' => (bool) ($extraction['review_required'] ?? true),
            'recommended_action' => data_get($normalized, 'recommended_action'),
            'overall_confidence' => (int) data_get($normalized, 'confidence.overall', 0),
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'estimated_cost_usd' => null,
            'cache_hit' => false,
            'cache_source' => null,
            'error_message' => $extraction['error_message'] ?? null,
            'extraction' => $extraction,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @param  array<string, mixed>  $options
     */
    private function shouldAttemptFallback(array $extraction, array $options): bool
    {
        if (($options['mode'] ?? null) === 'escalate') {
            return false;
        }

        if (($options['skip_fallback'] ?? false) === true) {
            return false;
        }

        $fallbackModel = $this->fallbackModel();
        if ($fallbackModel === null) {
            return false;
        }

        if ($fallbackModel === $this->requestedModel($options)) {
            return false;
        }

        if (($extraction['status'] ?? null) === 'failed') {
            return true;
        }

        return data_get($extraction, 'normalized.recommended_action') === 'escalate';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function fallbackOptions(array $options): array
    {
        return array_merge($options, [
            'mode' => 'escalate',
            'model' => $this->fallbackModel(),
            'trigger' => 'auto_fallback',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    private function selectBestAttempt(array $attempts): array
    {
        $selected = $attempts[0];
        $selectedScore = $this->attemptScore($selected);

        foreach ($attempts as $attempt) {
            $score = $this->attemptScore($attempt);
            if ($score > $selectedScore) {
                $selected = $attempt;
                $selectedScore = $score;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    private function attemptScore(array $attempt): int
    {
        $statusWeight = match ($attempt['status'] ?? null) {
            'completed' => 300,
            'skipped' => 200,
            default => 0,
        };

        return $statusWeight
            + (int) ($attempt['overall_confidence'] ?? 0)
            + ((bool) ($attempt['review_required'] ?? true) ? 0 : 25);
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     */
    private function aggregateUsage(array $attempts): array
    {
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $models = [];
        $attemptCount = 0;

        foreach ($attempts as $attempt) {
            if (($attempt['source'] ?? null) === 'cache') {
                continue;
            }

            $attemptCount++;
            $usage = is_array($attempt['usage'] ?? null) ? $attempt['usage'] : [];
            $promptTokens += (int) ($usage['prompt_tokens'] ?? 0);
            $completionTokens += (int) ($usage['completion_tokens'] ?? 0);
            $totalTokens += (int) ($usage['total_tokens'] ?? 0);

            $model = $attempt['model'] ?? null;
            if (is_string($model) && $model !== '') {
                $models[] = $model;
            }
        }

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'attempt_count' => $attemptCount,
            'models_tried' => array_values(array_unique($models)),
            'cache_hit' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     */
    private function applyEstimatedCosts(array &$attempts): void
    {
        foreach ($attempts as &$attempt) {
            $attempt['estimated_cost_usd'] = $this->estimateAttemptCost($attempt);
        }
        unset($attempt);
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    private function estimateAttemptCost(array $attempt): ?float
    {
        if (($attempt['source'] ?? null) === 'cache') {
            return 0.0;
        }

        $model = $attempt['model'] ?? $attempt['requested_model'] ?? null;
        if (! is_string($model) || $model === '') {
            return null;
        }

        $rates = $this->pricingRatesForModel($model);
        if ($rates === null) {
            return null;
        }

        $usage = is_array($attempt['usage'] ?? null) ? $attempt['usage'] : [];
        $inputCost = ((int) ($usage['prompt_tokens'] ?? 0) / 1_000_000) * $rates['input'];
        $outputCost = ((int) ($usage['completion_tokens'] ?? 0) / 1_000_000) * $rates['output'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     */
    private function aggregateEstimatedCost(array $attempts): ?float
    {
        $costs = array_values(array_filter(array_map(
            fn (array $attempt) => $attempt['estimated_cost_usd'] ?? null,
            $attempts
        ), static fn ($value) => $value !== null));

        if ($costs === []) {
            return null;
        }

        return round((float) array_sum($costs), 6);
    }

    private function pricingRatesForModel(string $model): ?array
    {
        $primaryModel = (string) config('services.openai.plan_scan_model', config('services.openai.model', 'gpt-4o-mini'));
        $fallbackModel = (string) config('services.openai.plan_scan_fallback_model', $primaryModel);

        if ($model === $primaryModel) {
            return $this->configuredRates(
                config('services.openai.plan_scan_primary_input_cost_per_1m'),
                config('services.openai.plan_scan_primary_output_cost_per_1m')
            );
        }

        if ($model === $fallbackModel) {
            return $this->configuredRates(
                config('services.openai.plan_scan_fallback_input_cost_per_1m'),
                config('services.openai.plan_scan_fallback_output_cost_per_1m')
            );
        }

        return null;
    }

    private function configuredRates(mixed $input, mixed $output): ?array
    {
        if (! is_numeric($input) || ! is_numeric($output)) {
            return null;
        }

        return [
            'input' => (float) $input,
            'output' => (float) $output,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function publicAttempts(array $attempts): array
    {
        return array_values(array_map(function (array $attempt) {
            unset($attempt['extraction']);

            return $attempt;
        }, $attempts));
    }

    /**
     * @param  array<string, mixed>  $finalExtraction
     * @param  array<int, array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    private function decorateRawPayload(
        array $finalExtraction,
        array $attempts,
        ?string $cacheKey,
        bool $cacheHit,
        ?string $cacheSource,
        ?float $estimatedCost
    ): array {
        $raw = is_array($finalExtraction['raw'] ?? null) ? $finalExtraction['raw'] : $finalExtraction;
        $raw['attempts'] = $attempts;
        $raw['cache'] = [
            'key' => $cacheKey,
            'hit' => $cacheHit,
            'source' => $cacheSource,
        ];
        $raw['estimated_cost_usd'] = $estimatedCost;

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function canCache(array $result): bool
    {
        return ($result['status'] ?? null) === 'completed';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function storeRuntimeCache(string $cacheKey, array $result): void
    {
        $ttlMinutes = max(1, (int) config('services.openai.plan_scan_cache_ttl', 1440));

        Cache::put($this->runtimeCacheKey($cacheKey), [
            'status' => $result['status'] ?? null,
            'model' => $result['model'] ?? null,
            'raw' => $result['raw'] ?? null,
            'normalized' => $result['normalized'] ?? null,
            'review_required' => $result['review_required'] ?? null,
            'error_message' => $result['error_message'] ?? null,
            'attempts' => $result['attempts'] ?? [],
            'cached_at' => Carbon::now()->toIso8601String(),
        ], now()->addMinutes($ttlMinutes));
    }

    private function runtimeCacheKey(string $cacheKey): string
    {
        return self::CACHE_PREFIX.':'.$cacheKey;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function shouldUseCache(array $options): bool
    {
        return ($options['bypass_cache'] ?? false) !== true
            && (int) config('services.openai.plan_scan_cache_ttl', 1440) > 0;
    }

    private function fallbackModel(): ?string
    {
        $fallbackModel = trim((string) config('services.openai.plan_scan_fallback_model', ''));

        return $fallbackModel !== '' ? $fallbackModel : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function requestedModel(array $options): string
    {
        $requestedModel = trim((string) ($options['model'] ?? ''));
        if ($requestedModel !== '') {
            return $requestedModel;
        }

        if (($options['mode'] ?? null) === 'escalate' && $this->fallbackModel() !== null) {
            return (string) $this->fallbackModel();
        }

        return (string) config('services.openai.plan_scan_model', config('services.openai.model', 'gpt-4o-mini'));
    }

    private function resolveFileHash(PlanScan $scan): ?string
    {
        if ($scan->plan_file_sha256) {
            return $scan->plan_file_sha256;
        }

        if (! $scan->plan_file_path || ! Storage::disk('public')->exists($scan->plan_file_path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($scan->plan_file_path);
        $hash = hash('sha256', $contents);

        $scan->forceFill([
            'plan_file_sha256' => $hash,
        ])->save();

        return $hash;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) round((float) $value));
    }
}
