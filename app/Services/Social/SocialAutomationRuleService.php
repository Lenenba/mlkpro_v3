<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SocialAutomationRuleService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $owner, User $actor, array $payload): SocialAutomationRule
    {
        $attributes = $this->ruleAttributes($owner, $actor, $payload);

        return SocialAutomationRule::query()->create($attributes)->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $owner, User $actor, SocialAutomationRule $rule, array $payload): SocialAutomationRule
    {
        $this->assertOwnership($owner, $rule);

        $attributes = $this->ruleAttributes($owner, $actor, [
            'name' => array_key_exists('name', $payload) ? $payload['name'] : $rule->name,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $rule->description,
            'is_active' => array_key_exists('is_active', $payload) ? $payload['is_active'] : $rule->is_active,
            'frequency_type' => array_key_exists('frequency_type', $payload) ? $payload['frequency_type'] : $rule->frequency_type,
            'frequency_interval' => array_key_exists('frequency_interval', $payload) ? $payload['frequency_interval'] : $rule->frequency_interval,
            'scheduled_time' => array_key_exists('scheduled_time', $payload) ? $payload['scheduled_time'] : $rule->scheduled_time,
            'timezone' => array_key_exists('timezone', $payload) ? $payload['timezone'] : $rule->timezone,
            'approval_mode' => array_key_exists('approval_mode', $payload) ? $payload['approval_mode'] : $rule->approval_mode,
            'language' => array_key_exists('language', $payload) ? $payload['language'] : $rule->language,
            'content_sources' => array_key_exists('content_sources', $payload) ? $payload['content_sources'] : $rule->content_sources,
            'target_connection_ids' => array_key_exists('target_connection_ids', $payload) ? $payload['target_connection_ids'] : $rule->target_connection_ids,
            'max_posts_per_day' => array_key_exists('max_posts_per_day', $payload) ? $payload['max_posts_per_day'] : $rule->max_posts_per_day,
            'min_hours_between_similar_posts' => array_key_exists('min_hours_between_similar_posts', $payload) ? $payload['min_hours_between_similar_posts'] : $rule->min_hours_between_similar_posts,
            'metadata' => array_key_exists('metadata', $payload) ? $payload['metadata'] : $rule->metadata,
            'generation_settings' => array_key_exists('generation_settings', $payload)
                ? $payload['generation_settings']
                : data_get($rule->metadata, 'generation_settings', []),
            'next_generation_at' => array_key_exists('next_generation_at', $payload) ? $payload['next_generation_at'] : null,
        ], $rule);

        $rule->forceFill($attributes)->save();

        return $rule->fresh();
    }

    public function pause(User $owner, SocialAutomationRule $rule): SocialAutomationRule
    {
        $this->assertOwnership($owner, $rule);

        $metadata = is_array($rule->metadata) ? $rule->metadata : [];
        $health = is_array($metadata['health'] ?? null) ? $metadata['health'] : [];
        $health['manually_paused_at'] = now()->toIso8601String();
        $metadata['health'] = $health;

        $rule->forceFill([
            'is_active' => false,
            'metadata' => $metadata,
        ])->save();

        return $rule->fresh();
    }

    public function resume(User $owner, SocialAutomationRule $rule): SocialAutomationRule
    {
        $this->assertOwnership($owner, $rule);

        $metadata = is_array($rule->metadata) ? $rule->metadata : [];
        $health = is_array($metadata['health'] ?? null) ? $metadata['health'] : [];

        if (! empty($health['auto_paused'])) {
            $health['last_auto_pause'] = array_filter([
                'at' => $health['auto_paused_at'] ?? null,
                'reason' => $health['auto_pause_reason'] ?? null,
                'code' => $health['auto_pause_code'] ?? null,
            ], fn ($value) => $value !== null);
        }

        unset(
            $health['consecutive_failures'],
            $health['last_failure_at'],
            $health['last_failure_code'],
            $health['last_failure_message'],
            $health['auto_paused'],
            $health['auto_paused_at'],
            $health['auto_pause_reason'],
            $health['auto_pause_code'],
            $health['auto_pause_threshold']
        );
        $health['last_resumed_at'] = now()->toIso8601String();
        $metadata['health'] = $health;

        $rule->forceFill([
            'is_active' => true,
            'next_generation_at' => $this->calculateNextGenerationAt($rule, now(), $owner),
            'metadata' => $metadata,
        ])->save();

        return $rule->fresh();
    }

    public function delete(User $owner, SocialAutomationRule $rule): void
    {
        $this->assertOwnership($owner, $rule);

        $rule->delete();
    }

    public function calculateNextGenerationAt(
        SocialAutomationRule|array $rule,
        Carbon|string|null $from = null,
        ?User $owner = null
    ): Carbon {
        $resolvedFrom = $from instanceof Carbon ? $from->copy() : Carbon::parse($from ?? now());
        $timezone = $this->resolveTimezone($rule, $owner);
        $localReference = $resolvedFrom->copy()->setTimezone($timezone);

        $frequencyType = $this->value($rule, 'frequency_type') ?: SocialAutomationRule::FREQUENCY_DAILY;
        $frequencyInterval = max(1, (int) ($this->value($rule, 'frequency_interval') ?: 1));
        if ($frequencyType === SocialAutomationRule::FREQUENCY_EVERY_TWO_DAYS) {
            $frequencyInterval = max(2, $frequencyInterval);
        }

        $scheduledTime = $this->normalizeScheduledTime(
            $this->value($rule, 'scheduled_time'),
            $frequencyType === SocialAutomationRule::FREQUENCY_HOURLY
                ? $localReference->format('H:i')
                : '09:00'
        );

        [$hour, $minute] = array_map('intval', explode(':', $scheduledTime));
        $metadata = $this->metadata($rule);
        $anchorBase = $this->anchorBase($rule, $localReference, $timezone);

        $next = match ($frequencyType) {
            SocialAutomationRule::FREQUENCY_HOURLY => (function () use ($localReference, $frequencyInterval, $minute): Carbon {
                $candidate = $localReference->copy()->addHours($frequencyInterval);
                $candidate->setTime((int) $candidate->format('H'), $minute, 0);

                return $candidate;
            })(),
            SocialAutomationRule::FREQUENCY_WEEKLY => $this->nextWeekly(
                $localReference,
                max(1, min(7, (int) ($metadata['day_of_week'] ?? $anchorBase->dayOfWeekIso))),
                $hour,
                $minute,
                $frequencyInterval
            ),
            SocialAutomationRule::FREQUENCY_MONTHLY => $this->nextMonthly(
                $localReference,
                max(1, min(31, (int) ($metadata['day_of_month'] ?? $anchorBase->day))),
                $hour,
                $minute,
                $frequencyInterval
            ),
            SocialAutomationRule::FREQUENCY_EVERY_TWO_DAYS => $this->nextDaily(
                $localReference,
                $hour,
                $minute,
                $frequencyInterval
            ),
            default => $this->nextDaily(
                $localReference,
                $hour,
                $minute,
                $frequencyInterval
            ),
        };

        return $next->utc();
    }

    public function normalizeContentSources(mixed $value): array
    {
        $sources = is_array($value) ? $value : [];

        return collect($sources)
            ->map(function (mixed $item): ?array {
                if (is_string($item)) {
                    $type = strtolower(trim($item));

                    return in_array($type, SocialPrefillService::allowedSourceTypes(), true)
                        ? ['type' => $type, 'mode' => 'all']
                        : null;
                }

                if (! is_array($item)) {
                    return null;
                }

                $type = strtolower(trim((string) ($item['type'] ?? '')));
                if (! in_array($type, SocialPrefillService::allowedSourceTypes(), true)) {
                    return null;
                }

                $mode = strtolower(trim((string) ($item['mode'] ?? 'all')));
                $mode = in_array($mode, ['all', 'selected_ids'], true) ? $mode : 'all';

                $ids = collect((array) ($item['ids'] ?? []))
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                return array_filter([
                    'type' => $type,
                    'mode' => $mode,
                    'ids' => $mode === 'selected_ids' ? $ids : null,
                ], fn ($candidate) => $candidate !== null);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public function normalizeTargetConnectionIds(mixed $value): array
    {
        return collect((array) $value)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeGenerationSettings(mixed $value): array
    {
        $settings = is_array($value) ? $value : [];
        $defaults = SocialAutomationRule::defaultGenerationSettings();

        $tone = strtolower(trim((string) ($settings['tone'] ?? $defaults['tone'])));
        if (! in_array($tone, SocialAutomationRule::allowedAiTones(), true)) {
            $tone = $defaults['tone'];
        }

        $goal = strtolower(trim((string) ($settings['goal'] ?? $defaults['goal'])));
        if (! in_array($goal, SocialAutomationRule::allowedAiGoals(), true)) {
            $goal = $defaults['goal'];
        }

        $imageMode = strtolower(trim((string) ($settings['image_mode'] ?? $defaults['image_mode'])));
        if (! in_array($imageMode, SocialAutomationRule::allowedAiImageModes(), true)) {
            $imageMode = $defaults['image_mode'];
        }

        $imageFormat = strtolower(trim((string) ($settings['image_format'] ?? $defaults['image_format'])));
        if (! in_array($imageFormat, SocialAutomationRule::allowedAiImageFormats(), true)) {
            $imageFormat = $defaults['image_format'];
        }

        return [
            'text_ai_enabled' => $this->booleanValue($settings['text_ai_enabled'] ?? $defaults['text_ai_enabled']),
            'image_ai_enabled' => $this->booleanValue($settings['image_ai_enabled'] ?? $defaults['image_ai_enabled']),
            'creative_prompt' => $this->limitedString($settings['creative_prompt'] ?? $defaults['creative_prompt']),
            'image_prompt' => $this->limitedString($settings['image_prompt'] ?? $defaults['image_prompt']),
            'tone' => $tone,
            'goal' => $goal,
            'image_mode' => $imageMode,
            'image_format' => $imageFormat,
            'variant_count' => max(1, min(5, (int) ($settings['variant_count'] ?? $defaults['variant_count']))),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ruleAttributes(User $owner, User $actor, array $payload, ?SocialAutomationRule $existing = null): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Give this Pulse automation rule a clear name before saving it.',
            ]);
        }

        $frequencyType = strtolower(trim((string) ($payload['frequency_type'] ?? SocialAutomationRule::FREQUENCY_DAILY)));
        if (! in_array($frequencyType, SocialAutomationRule::allowedFrequencyTypes(), true)) {
            throw ValidationException::withMessages([
                'frequency_type' => 'Choose a valid Pulse automation frequency.',
            ]);
        }

        $approvalMode = strtolower(trim((string) ($payload['approval_mode'] ?? SocialAutomationRule::APPROVAL_REQUIRED)));
        if (! in_array($approvalMode, SocialAutomationRule::allowedApprovalModes(), true)) {
            throw ValidationException::withMessages([
                'approval_mode' => 'Choose a valid Pulse automation approval mode.',
            ]);
        }

        $contentSources = $this->normalizeContentSources($payload['content_sources'] ?? []);
        if ($contentSources === []) {
            throw ValidationException::withMessages([
                'content_sources' => 'Select at least one content source before saving this Pulse automation rule.',
            ]);
        }

        $targetConnectionIds = $this->normalizeTargetConnectionIds($payload['target_connection_ids'] ?? []);
        if ($targetConnectionIds === []) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Select at least one connected social account before saving this Pulse automation rule.',
            ]);
        }

        $existingConnectionIds = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->whereKey($targetConnectionIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($existingConnectionIds) !== count($targetConnectionIds)) {
            throw ValidationException::withMessages([
                'target_connection_ids' => 'Only accounts that belong to this tenant can be targeted by a Pulse automation rule.',
            ]);
        }

        $frequencyInterval = max(1, (int) ($payload['frequency_interval'] ?? 1));
        if ($frequencyType === SocialAutomationRule::FREQUENCY_EVERY_TWO_DAYS) {
            $frequencyInterval = max(2, $frequencyInterval);
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $metadata['generation_settings'] = $this->normalizeGenerationSettings(
            $payload['generation_settings'] ?? ($metadata['generation_settings'] ?? [])
        );

        $anchorNow = now()->setTimezone($this->resolveTimezone($payload, $owner));
        $metadata['day_of_week'] = max(1, min(7, (int) ($metadata['day_of_week'] ?? $anchorNow->dayOfWeekIso)));
        $metadata['day_of_month'] = max(1, min(31, (int) ($metadata['day_of_month'] ?? $anchorNow->day)));

        $nextGenerationAt = array_key_exists('next_generation_at', $payload) && $payload['next_generation_at']
            ? Carbon::parse($payload['next_generation_at'])->utc()
            : $this->calculateNextGenerationAt(array_merge($payload, [
                'frequency_type' => $frequencyType,
                'frequency_interval' => $frequencyInterval,
                'timezone' => $this->resolveTimezone($payload, $owner),
                'scheduled_time' => $payload['scheduled_time'] ?? null,
                'metadata' => $metadata,
                'created_at' => $existing?->created_at ?: now(),
                'last_generated_at' => $existing?->last_generated_at,
            ]), now(), $owner);

        return [
            'user_id' => $owner->id,
            'created_by_user_id' => $existing?->created_by_user_id ?: $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => $name,
            'description' => $this->nullableString($payload['description'] ?? null),
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'frequency_type' => $frequencyType,
            'frequency_interval' => $frequencyInterval,
            'scheduled_time' => $this->normalizeScheduledTime(
                $payload['scheduled_time'] ?? null,
                $frequencyType === SocialAutomationRule::FREQUENCY_HOURLY ? now()->format('H:i') : '09:00'
            ),
            'timezone' => $this->resolveTimezone($payload, $owner),
            'approval_mode' => $approvalMode,
            'language' => LocalePreference::normalize((string) ($payload['language'] ?? $owner->locale)),
            'content_sources' => $contentSources,
            'target_connection_ids' => $targetConnectionIds,
            'max_posts_per_day' => max(1, (int) ($payload['max_posts_per_day'] ?? 1)),
            'min_hours_between_similar_posts' => max(1, (int) ($payload['min_hours_between_similar_posts'] ?? 24)),
            'next_generation_at' => $nextGenerationAt,
            'metadata' => $metadata,
        ];
    }

    private function resolveTimezone(SocialAutomationRule|array $rule, ?User $owner = null): string
    {
        $candidate = trim((string) $this->value($rule, 'timezone'));
        if ($candidate !== '' && in_array($candidate, timezone_identifiers_list(), true)) {
            return $candidate;
        }

        $ownerTimezone = trim((string) ($owner?->company_timezone ?? ''));

        return in_array($ownerTimezone, timezone_identifiers_list(), true)
            ? $ownerTimezone
            : (string) config('app.timezone', 'UTC');
    }

    private function normalizeScheduledTime(mixed $value, string $fallback): string
    {
        $candidate = trim((string) $value);

        return preg_match('/^\d{2}:\d{2}$/', $candidate) === 1
            ? $candidate
            : $fallback;
    }

    private function nextDaily(Carbon $reference, int $hour, int $minute, int $days): Carbon
    {
        $candidate = $reference->copy()->setTime($hour, $minute, 0);
        if ($candidate->lessThanOrEqualTo($reference)) {
            $candidate->addDays($days);
        }

        return $candidate;
    }

    private function nextWeekly(Carbon $reference, int $dayOfWeekIso, int $hour, int $minute, int $weeks): Carbon
    {
        $candidate = $reference->copy()->setTime($hour, $minute, 0);

        while ((int) $candidate->dayOfWeekIso !== $dayOfWeekIso || $candidate->lessThanOrEqualTo($reference)) {
            $candidate->addDay()->setTime($hour, $minute, 0);
        }

        if ($weeks > 1) {
            $candidate->addWeeks($weeks - 1);
        }

        return $candidate;
    }

    private function nextMonthly(Carbon $reference, int $dayOfMonth, int $hour, int $minute, int $months): Carbon
    {
        $candidate = $reference->copy()->setTime($hour, $minute, 0);
        $candidate->day(min($dayOfMonth, $candidate->daysInMonth));

        if ($candidate->lessThanOrEqualTo($reference)) {
            $candidate->addMonthsNoOverflow($months);
            $candidate->day(min($dayOfMonth, $candidate->daysInMonth));
            $candidate->setTime($hour, $minute, 0);
        }

        return $candidate;
    }

    private function anchorBase(SocialAutomationRule|array $rule, Carbon $fallback, string $timezone): Carbon
    {
        $candidate = $this->value($rule, 'last_generated_at')
            ?: $this->value($rule, 'created_at');

        return $candidate
            ? Carbon::parse((string) $candidate)->setTimezone($timezone)
            : $fallback->copy();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(SocialAutomationRule|array $rule): array
    {
        $metadata = $this->value($rule, 'metadata');

        return is_array($metadata) ? $metadata : [];
    }

    private function assertOwnership(User $owner, SocialAutomationRule $rule): void
    {
        if ((int) $rule->user_id !== (int) $owner->id) {
            abort(404);
        }
    }

    private function value(SocialAutomationRule|array $rule, string $key): mixed
    {
        if ($rule instanceof SocialAutomationRule) {
            return $rule->{$key};
        }

        return $rule[$key] ?? null;
    }

    private function nullableString(mixed $value): ?string
    {
        $candidate = trim((string) $value);

        return $candidate !== '' ? $candidate : null;
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered ?? (bool) $value;
    }

    private function limitedString(mixed $value, int $limit = 1000): string
    {
        $candidate = trim((string) $value);

        return mb_substr($candidate, 0, max(0, $limit));
    }
}
