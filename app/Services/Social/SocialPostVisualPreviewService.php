<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Str;

class SocialPostVisualPreviewService
{
    /**
     * @return array<string, mixed>
     */
    public function approvalEmailPayload(
        SocialPost $post,
        User $owner,
        User $recipient,
        ?SocialApprovalRequest $approvalRequest = null
    ): array {
        $post->loadMissing([
            'automationRule',
            'targets.socialAccountConnection',
            'latestApprovalRequest.requestedBy',
        ]);

        $approvalRequest ??= $post->latestApprovalRequest;
        $text = trim((string) data_get($post->content_payload, 'text', ''));
        $imageUrl = $this->resolveImageUrl($this->firstImageUrl((array) ($post->media_payload ?? [])));
        $linkUrl = trim((string) ($post->link_url ?? ''));
        $companyName = trim((string) ($owner->company_name ?: $owner->name ?: config('app.name')));

        return [
            'subject' => $this->subject($post),
            'preheader' => $this->preheader($post),
            'companyName' => $companyName,
            'recipientName' => $recipient->name ?: $recipient->email,
            'approvalUrl' => route('social.approvals.index'),
            'requestedBy' => $approvalRequest?->requestedBy?->name
                ?: $approvalRequest?->requestedBy?->email,
            'requestedAt' => optional($approvalRequest?->requested_at)->toDayDateTimeString(),
            'sourceLabel' => data_get($post->metadata, 'source.label'),
            'ruleName' => $post->automationRule?->name,
            'text' => $text,
            'imageUrl' => $imageUrl,
            'linkUrl' => $linkUrl !== '' ? $linkUrl : null,
            'previews' => $this->previews($post, $owner, $text, $imageUrl, $linkUrl),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previews(SocialPost $post, User $owner, string $text, ?string $imageUrl, string $linkUrl): array
    {
        $targets = $post->targets;

        if ($targets->isEmpty()) {
            return [[
                'platform' => 'generic',
                'platform_label' => 'Pulse',
                'layout' => 'feed',
                'accent' => '#44403c',
                'account_name' => $this->companyName($owner),
                'account_meta' => 'Apercu social',
                'text' => $text,
                'image_url' => $imageUrl,
                'link_url' => $linkUrl !== '' ? $linkUrl : null,
                'avatar_initial' => $this->initial($this->companyName($owner)),
            ]];
        }

        return $targets
            ->map(function ($target) use ($owner, $text, $imageUrl, $linkUrl): array {
                $connection = $target->socialAccountConnection;
                $platform = strtolower(trim((string) ($connection?->platform ?? data_get($target->metadata, 'platform', 'generic'))));
                $definition = $this->platformDefinition($platform);
                $accountName = $this->accountName($connection, $target->metadata, $owner);

                return [
                    'platform' => $platform,
                    'platform_label' => $definition['label'],
                    'layout' => $definition['layout'],
                    'accent' => $definition['accent'],
                    'account_name' => $accountName,
                    'account_meta' => $this->accountMeta($connection, $target->metadata, $definition['label']),
                    'text' => $text,
                    'image_url' => $imageUrl,
                    'link_url' => $linkUrl !== '' ? $linkUrl : null,
                    'avatar_initial' => $this->initial($accountName),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, layout: string, accent: string}
     */
    private function platformDefinition(string $platform): array
    {
        return match ($platform) {
            SocialAccountConnection::PLATFORM_INSTAGRAM => [
                'label' => 'Instagram',
                'layout' => 'instagram',
                'accent' => '#262626',
            ],
            SocialAccountConnection::PLATFORM_FACEBOOK => [
                'label' => 'Facebook',
                'layout' => 'facebook',
                'accent' => '#1877f2',
            ],
            SocialAccountConnection::PLATFORM_LINKEDIN => [
                'label' => 'LinkedIn',
                'layout' => 'linkedin',
                'accent' => '#0a66c2',
            ],
            SocialAccountConnection::PLATFORM_X => [
                'label' => 'X',
                'layout' => 'x',
                'accent' => '#111111',
            ],
            default => [
                'label' => Str::headline($platform ?: 'Pulse'),
                'layout' => 'feed',
                'accent' => '#44403c',
            ],
        };
    }

    private function subject(SocialPost $post): string
    {
        $source = trim((string) data_get($post->metadata, 'source.label', ''));

        return $source !== ''
            ? 'Pulse: validation de '.$source
            : 'Pulse: post a valider';
    }

    private function preheader(SocialPost $post): string
    {
        $targets = $post->targets
            ->map(fn ($target): string => (string) ($target->socialAccountConnection?->platform ?? data_get($target->metadata, 'platform', '')))
            ->filter()
            ->map(fn (string $platform): string => $this->platformDefinition(strtolower($platform))['label'])
            ->unique()
            ->implode(', ');

        return $targets !== ''
            ? 'Apercu du post avant validation: '.$targets.'.'
            : 'Apercu du post avant validation.';
    }

    private function accountName(?SocialAccountConnection $connection, ?array $metadata, User $owner): string
    {
        $candidate = trim((string) (
            $connection?->display_name
            ?: data_get($metadata, 'display_name')
            ?: $connection?->label
            ?: data_get($metadata, 'snapshot_label')
            ?: $this->companyName($owner)
        ));

        return $candidate !== '' ? $candidate : $this->companyName($owner);
    }

    private function accountMeta(?SocialAccountConnection $connection, ?array $metadata, string $fallback): string
    {
        $handle = trim((string) ($connection?->account_handle ?: data_get($metadata, 'account_handle', '')));
        if ($handle !== '') {
            return str_starts_with($handle, '@') ? $handle : '@'.$handle;
        }

        return $fallback;
    }

    private function companyName(User $owner): string
    {
        return trim((string) ($owner->company_name ?: $owner->name ?: config('app.name')));
    }

    private function initial(string $value): string
    {
        $initial = Str::upper(Str::substr(trim($value), 0, 1));

        return $initial !== '' ? $initial : 'P';
    }

    private function firstImageUrl(array $mediaPayload): ?string
    {
        foreach ($mediaPayload as $item) {
            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function resolveImageUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        return url($url);
    }
}
