<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;

final class SocialPostRevisionSnapshotService
{
    public function hashForRevision(SocialPostRevision $revision): string
    {
        return $this->canonicalHash([
            'base_content' => (array) $revision->base_content,
            'source_snapshot' => (array) $revision->source_snapshot,
            'media_snapshot' => (array) $revision->media_snapshot,
            'scheduled_for' => $revision->scheduled_for?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'scheduled_timezone' => (string) $revision->scheduled_timezone,
            'scheduled_local_time' => $revision->scheduled_local_time?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{rule_id:int,approval_mode:string,policy_fingerprint:string,rule_updated_at:string}
     */
    public function autopilotPolicyForRule(SocialAutomationRule $rule): array
    {
        if ((int) $rule->id <= 0
            || (int) $rule->user_id <= 0
            || ! in_array((string) $rule->approval_mode, SocialAutomationRule::allowedApprovalModes(), true)
            || ! $rule->updated_at instanceof DateTimeInterface) {
            throw new InvalidArgumentException('The Pulse Autopilot policy snapshot requires a valid persisted rule.');
        }

        return [
            'rule_id' => (int) $rule->id,
            'approval_mode' => (string) $rule->approval_mode,
            'policy_fingerprint' => $this->canonicalHash([
                'approval_mode' => (string) $rule->approval_mode,
                'content_sources' => (array) $rule->content_sources,
                'frequency_interval' => (int) $rule->frequency_interval,
                'frequency_type' => (string) $rule->frequency_type,
                'generation_settings' => (array) data_get($rule->metadata, 'generation_settings', []),
                'is_active' => (bool) $rule->is_active,
                'language' => (string) $rule->language,
                'max_posts_per_day' => (int) $rule->max_posts_per_day,
                'min_hours_between_similar_posts' => (int) $rule->min_hours_between_similar_posts,
                'scheduled_time' => (string) $rule->scheduled_time,
                'target_connection_ids' => (array) $rule->target_connection_ids,
                'timezone' => (string) $rule->timezone,
            ]),
            'rule_updated_at' => Carbon::instance($rule->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $expected
     */
    public function autopilotPoliciesMatch(array $current, array $expected): bool
    {
        $currentFingerprint = (string) ($current['policy_fingerprint'] ?? '');
        $expectedFingerprint = (string) ($expected['policy_fingerprint'] ?? '');

        return preg_match('/\A[0-9a-f]{64}\z/', $currentFingerprint) === 1
            && preg_match('/\A[0-9a-f]{64}\z/', $expectedFingerprint) === 1
            && hash_equals($currentFingerprint, $expectedFingerprint)
            && (int) ($current['rule_id'] ?? 0) === (int) ($expected['rule_id'] ?? 0)
            && (string) ($current['approval_mode'] ?? '') === (string) ($expected['approval_mode'] ?? '')
            && (string) ($current['rule_updated_at'] ?? '') === (string) ($expected['rule_updated_at'] ?? '');
    }

    /**
     * @return array{
     *     base_content:array<string,mixed>,
     *     source_snapshot:array<string,mixed>,
     *     media_snapshot:array{schema_version:int,items:array<int,mixed>},
     *     scheduled_for:string|null,
     *     scheduled_timezone:string,
     *     scheduled_local_time:string|null,
     *     payload_hash:string
     * }
     */
    public function forPost(SocialPost $post, string $timezone): array
    {
        $timezone = trim($timezone);

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('The Pulse editorial snapshot timezone must be a valid IANA identifier.');
        }

        $scheduledFor = $post->scheduled_for instanceof Carbon
            ? $post->scheduled_for->copy()->utc()
            : null;
        $snapshot = [
            'base_content' => [
                'content_payload' => (array) ($post->content_payload ?? []),
                'link_cta_label' => $this->nullableString(data_get($post->metadata, 'link_cta_label')),
                'link_url' => $this->nullableString($post->link_url),
            ],
            'source_snapshot' => $this->sourceSnapshot($post),
            'media_snapshot' => [
                'schema_version' => 1,
                'items' => is_array($post->media_payload)
                    ? array_values($post->media_payload)
                    : [],
            ],
            'scheduled_for' => $scheduledFor?->format('Y-m-d\TH:i:s\Z'),
            'scheduled_timezone' => $timezone,
            'scheduled_local_time' => $scheduledFor?->copy()
                ->setTimezone($timezone)
                ->format('Y-m-d H:i:s'),
        ];
        $canonicalSnapshot = $this->canonicalize($snapshot);

        try {
            $encoded = json_encode(
                $canonicalSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Pulse editorial snapshot cannot be encoded.', 0, $exception);
        }

        return [
            ...$canonicalSnapshot,
            'payload_hash' => hash('sha256', $encoded),
        ];
    }

    /**
     * @return array{
     *     autopilot_policy:array{rule_id:int,approval_mode:string,policy_fingerprint:string,rule_updated_at:string}|null,
     *     source_id:int|null,
     *     source_label:string|null,
     *     source_type:string|null
     * }
     */
    private function sourceSnapshot(SocialPost $post): array
    {
        return [
            'autopilot_policy' => $this->autopilotPolicySnapshot($post),
            'source_id' => $post->source_id ? (int) $post->source_id : null,
            'source_label' => $this->nullableString(data_get($post->metadata, 'source.label'))
                ?? $this->nullableString(data_get($post->metadata, 'automation.selected_source_label')),
            'source_type' => $this->nullableString($post->source_type),
        ];
    }

    /**
     * @return array{rule_id:int,approval_mode:string,policy_fingerprint:string,rule_updated_at:string}|null
     */
    private function autopilotPolicySnapshot(SocialPost $post): ?array
    {
        $ruleId = (int) $post->social_automation_rule_id;

        if ($ruleId <= 0) {
            return null;
        }

        if ($post->exists) {
            $rule = SocialAutomationRule::query()
                ->whereKey($ruleId)
                ->where('user_id', $post->user_id)
                ->first();
        } else {
            $rule = $post->relationLoaded('automationRule')
                ? $post->getRelation('automationRule')
                : null;
        }

        if (! $rule instanceof SocialAutomationRule
            || (int) $rule->id !== $ruleId
            || (int) $rule->user_id !== (int) $post->user_id) {
            throw new InvalidArgumentException(
                'The Pulse Autopilot policy snapshot must reference a valid rule from the post workspace.'
            );
        }

        return $this->autopilotPolicyForRule($rule);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalHash(array $value): string
    {
        try {
            $encoded = json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The Pulse Autopilot policy cannot be encoded.', 0, $exception);
        }

        return hash('sha256', $encoded);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
