<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Services\TenantBrandingResolver;
use App\Support\LocalePreference;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SocialPublicationCompletedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  array{tenant_id:int,social_post_id:int,excerpt:string,outcome:string,counts:array{total:int,published:int,failed:int,canceled:int,unknown:int},results:array<int,array<string,mixed>>,completed_at:string}  $snapshot
     */
    public function __construct(public array $snapshot)
    {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function canReceive(User $user): bool
    {
        $user->loadMissing(['role', 'teamMembership']);
        if ($user->isClient()) {
            return false;
        }

        $member = $user->teamMembership;
        $hasAccess = (int) $user->id === $this->snapshot['tenant_id'] || (
            $member && $member->is_active && (int) $member->account_id === $this->snapshot['tenant_id']
            && ($member->hasPermission('social.view') || $member->hasPermission('social.manage')
                || $member->hasPermission('social.publish') || $member->hasPermission('social.approve'))
        );

        return $hasAccess && app(NotificationPreferenceService::class)->shouldNotify(
            $user, NotificationPreferenceService::CATEGORY_SYSTEM, NotificationPreferenceService::CHANNEL_IN_APP,
        );
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && $this->canReceive($notifiable)
            && ! $notifiable->notifications()->whereKey($this->id)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content = $this->toArray($notifiable);
        $locale = LocalePreference::forNotifiable($notifiable);
        $translate = fn (string $key): string => LocalePreference::trans('social_publication_notifications.'.$key, locale: $locale);
        $owner = $notifiable instanceof User && (int) $notifiable->id === $this->snapshot['tenant_id']
            ? $notifiable
            : User::query()->find($this->snapshot['tenant_id']);
        $branding = app(TenantBrandingResolver::class)->forAccountOwner($owner);
        $results = collect($this->snapshot['results'])->map(fn (array $result): array => [
            ...$result,
            'platform_label' => $this->platformLabel((string) $result['platform']),
            'status_label' => $translate('statuses.'.($result['reconnect_required'] ? 'reconnect_required' : $result['status'])),
        ])->all();

        return (new MailMessage)
            ->subject($content['title'])
            ->view(['html' => 'emails.social.publication-completed', 'text' => 'emails.social.publication-completed-text'], [
                'content' => $content,
                'companyName' => $branding['name'],
                'companyLogo' => $branding['custom_logo_url'],
                'subject' => $content['title'],
                'summary' => strtok($content['message'], "\n"),
                'snapshot' => $this->snapshot,
                'results' => $results,
                'publicationLabel' => $translate('email.publication'),
                'detailsLabel' => $translate('email.details'),
                'actionLabel' => $translate('email.action'),
                'metrics' => [
                    ['value' => (string) $this->snapshot['counts']['published'], 'label' => $translate('email.published')],
                    ['value' => (string) $this->snapshot['counts']['failed'], 'label' => $translate('email.failed')],
                    ['value' => (string) $this->snapshot['counts']['total'], 'label' => $translate('email.accounts')],
                ],
                'actionUrl' => $content['action_url'],
                'nextSteps' => $this->snapshot['outcome'] === 'success' ? null : $translate('next_steps.'.$this->snapshot['outcome']),
            ]);
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn',
            'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'threads' => 'Threads',
            'twitter', 'x' => 'X', 'pinterest' => 'Pinterest',
            default => ucfirst($platform),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $locale = LocalePreference::forNotifiable($notifiable);
        $translate = fn (string $key, array $parameters = []): string => LocalePreference::trans(
            'social_publication_notifications.'.$key, $parameters, $locale,
        );
        $summary = $translate('summary', $this->snapshot['counts']);
        $lines = [$summary, '#'.$this->snapshot['social_post_id'].' — '.$this->snapshot['excerpt']];

        foreach ($this->snapshot['results'] as $result) {
            $platform = $this->platformLabel((string) $result['platform']);
            $status = $result['reconnect_required'] ? 'reconnect_required' : $result['status'];
            $line = $platform.($result['account'] !== '' ? ' ('.$result['account'].')' : '')
                .' : '.$translate('statuses.'.$status);
            if ($result['error']) {
                $line .= ' — '.$result['error'];
            }
            $lines[] = $line;
        }

        if ($this->snapshot['outcome'] !== 'success') {
            $lines[] = $translate('next_steps.'.$this->snapshot['outcome']);
        }

        return [
            'title' => $translate('titles.'.$this->snapshot['outcome']),
            'message' => implode("\n", $lines),
            'action_url' => route('social.history'),
            'category' => NotificationPreferenceService::CATEGORY_SYSTEM,
            'event' => 'social_publication_completed',
            'social_post_id' => $this->snapshot['social_post_id'],
            'outcome' => $this->snapshot['outcome'],
            'publication_summary' => $this->snapshot,
        ];
    }
}
