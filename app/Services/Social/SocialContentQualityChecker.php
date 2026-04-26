<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SocialContentQualityChecker
{
    public function __construct(
        private readonly SocialAccountConnectionService $connectionService,
    ) {}

    /**
     * @return array{passes: bool, message: string|null, connections: Collection<int, SocialAccountConnection>}
     */
    public function validateTargets(User $owner, SocialAutomationRule $rule, ?Carbon $now = null): array
    {
        $targetConnectionIds = collect((array) ($rule->target_connection_ids ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($targetConnectionIds->isEmpty()) {
            return [
                'passes' => false,
                'message' => 'No Pulse social account is attached to this automation rule.',
                'connections' => collect(),
            ];
        }

        $connections = SocialAccountConnection::query()
            ->byUser($owner->id)
            ->whereKey($targetConnectionIds->all())
            ->get()
            ->keyBy('id');

        $resolvedConnections = collect();
        $resolvedNow = $now ?? now();

        foreach ($targetConnectionIds as $connectionId) {
            $connection = $connections->get($connectionId);
            if (! $connection) {
                return [
                    'passes' => false,
                    'message' => 'One of the selected Pulse accounts no longer belongs to this workspace.',
                    'connections' => collect(),
                ];
            }

            if ($connection->token_expires_at instanceof Carbon
                && $connection->token_expires_at->lessThanOrEqualTo($resolvedNow->copy()->addMinutes(5))) {
                $connection = $this->connectionService->refresh($owner, $connection)->fresh();
            }

            if (! $this->connectionReady($connection, $resolvedNow)) {
                return [
                    'passes' => false,
                    'message' => sprintf('Pulse account "%s" is not ready for automated publishing.', $connection->label ?: $connection->platform),
                    'connections' => collect(),
                ];
            }

            $resolvedConnections->push($connection);
        }

        return [
            'passes' => true,
            'message' => null,
            'connections' => $resolvedConnections,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{passes: bool, message: string|null}
     */
    public function validateCandidate(
        User $owner,
        SocialAutomationRule $rule,
        array $candidate,
        ?Carbon $now = null,
        ?int $ignorePostId = null
    ): array {
        $text = trim((string) data_get($candidate, 'content_payload.text', ''));
        $imageUrl = trim((string) data_get($candidate, 'media_payload.0.url', ''));
        $linkUrl = trim((string) ($candidate['link_url'] ?? ''));

        if ($text === '' && $imageUrl === '' && $linkUrl === '') {
            return [
                'passes' => false,
                'message' => 'Pulse could not generate enough content from the selected automation source.',
            ];
        }

        $resolvedNow = $now ?? now();

        if ($this->hasReachedDailyQuota($owner, $rule, $resolvedNow, $ignorePostId)) {
            return [
                'passes' => false,
                'message' => 'This Pulse automation rule already reached its daily publication candidate limit.',
            ];
        }

        $fingerprint = trim((string) ($candidate['content_fingerprint'] ?? ''));
        if ($fingerprint !== '' && $this->hasRecentFingerprint($rule, $fingerprint, $resolvedNow, $ignorePostId)) {
            return [
                'passes' => false,
                'message' => 'Pulse skipped this candidate because the generated content is too similar to a recent automation post.',
            ];
        }

        return [
            'passes' => true,
            'message' => null,
        ];
    }

    private function connectionReady(SocialAccountConnection $connection, Carbon $now): bool
    {
        if (! $connection->is_active || (string) $connection->status !== SocialAccountConnection::STATUS_CONNECTED) {
            return false;
        }

        return ! ($connection->token_expires_at instanceof Carbon && $connection->token_expires_at->lessThanOrEqualTo($now));
    }

    private function hasReachedDailyQuota(
        User $owner,
        SocialAutomationRule $rule,
        Carbon $now,
        ?int $ignorePostId = null
    ): bool {
        $timezone = trim((string) ($rule->timezone ?: $owner->company_timezone ?: config('app.timezone', 'UTC')));
        $timezone = in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');

        $localNow = $now->copy()->setTimezone($timezone);
        $dayStart = $localNow->copy()->startOfDay()->utc();
        $dayEnd = $localNow->copy()->endOfDay()->utc();

        return SocialPost::query()
            ->where('social_automation_rule_id', $rule->id)
            ->when($ignorePostId, fn ($query, $postId) => $query->whereKeyNot($postId))
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->count() >= max(1, (int) ($rule->max_posts_per_day ?: 1));
    }

    private function hasRecentFingerprint(
        SocialAutomationRule $rule,
        string $fingerprint,
        Carbon $now,
        ?int $ignorePostId = null
    ): bool {
        $cutoff = $now->copy()->subHours(max(1, (int) ($rule->min_hours_between_similar_posts ?: 24)));

        return SocialPost::query()
            ->where('social_automation_rule_id', $rule->id)
            ->when($ignorePostId, fn ($query, $postId) => $query->whereKeyNot($postId))
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->get(['metadata'])
            ->contains(function (SocialPost $post) use ($fingerprint): bool {
                return (string) data_get($post->metadata, 'automation.content_fingerprint') === $fingerprint;
            });
    }
}
