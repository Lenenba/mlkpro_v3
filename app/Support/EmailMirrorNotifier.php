<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\EmailMirrorNotification;
use App\Services\NotificationPreferenceService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class EmailMirrorNotifier
{
    public static function recordQueued(Notification $notification, object $notifiable): void
    {
        self::record($notification, $notifiable, 'queued');
    }

    public static function recordStatus(Notification $notification, object $notifiable, string $status): array
    {
        return self::record($notification, $notifiable, $status, true);
    }

    private static function record(Notification $notification, object $notifiable, string $status, bool $returnRecipients = false): array
    {
        $channels = method_exists($notification, 'via') ? $notification->via($notifiable) : [];
        if (!in_array('mail', $channels, true)) {
            return ['payload' => null, 'recipients' => collect()];
        }

        $recipients = self::resolveRecipients($notifiable);
        if ($recipients->isEmpty()) {
            return ['payload' => null, 'recipients' => collect()];
        }

        $payload = self::buildPayload($notification, $notifiable, $status);
        if (!$payload) {
            return ['payload' => null, 'recipients' => collect()];
        }

        $dedupeKey = (string) ($payload['data']['dedupe_key'] ?? '');
        $pushRecipients = collect();

        foreach ($recipients as $user) {
            $category = (string) ($payload['category'] ?? NotificationPreferenceService::CATEGORY_EMAILS_MIRROR);
            $preferences = app(NotificationPreferenceService::class);
            if (!$preferences->shouldNotify($user, $category, NotificationPreferenceService::CHANNEL_IN_APP)) {
                continue;
            }
            if ($status !== 'queued') {
                $existingQueued = $dedupeKey !== '' ? self::findQueuedNotification($user, $dedupeKey) : null;
                if ($existingQueued) {
                    $data = $existingQueued->data ?? [];
                    $data['email_status'] = $status;
                    $existingQueued->forceFill(['data' => $data])->save();
                    if ($returnRecipients) {
                        $pushRecipients->push($user);
                    }
                    continue;
                }
            }

            if ($dedupeKey !== '' && self::shouldSkipDuplicate($user, $dedupeKey)) {
                continue;
            }

            $user->notify(new EmailMirrorNotification(
                $payload['title'],
                $payload['message'],
                $payload['action_url'] ?? null,
                $payload['category'] ?? null,
                $payload['data'] ?? []
            ));

            if ($returnRecipients && $status !== 'queued') {
                $pushRecipients->push($user);
            }
        }

        return ['payload' => $payload, 'recipients' => $pushRecipients];
    }

    private static function resolveRecipients(object $notifiable): Collection
    {
        if ($notifiable instanceof User) {
            return collect([$notifiable]);
        }

        if ($notifiable instanceof Customer) {
            $portalUser = $notifiable->relationLoaded('portalUser')
                ? $notifiable->portalUser
                : ($notifiable->portal_user_id
                    ? User::query()->select(['id', 'email'])->find($notifiable->portal_user_id)
                    : null);

            return $portalUser ? collect([$portalUser]) : collect();
        }

        return collect();
    }

    private static function buildPayload(Notification $notification, object $notifiable, string $status): array
    {
        $mailMessage = self::resolveMailMessage($notification, $notifiable);
        $title = self::resolveTitle($notification, $mailMessage);
        $message = self::resolveMessage($notification, $mailMessage, $title);
        $actionUrl = self::resolveActionUrl($notification, $mailMessage);
        $dedupeKey = self::buildDedupeKey($notification, $title, $message, $actionUrl);

        if ($status === 'queued' && stripos($message, 'email envoye') === 0) {
            $message = 'Email en file d\'attente.';
        }

        $data = [
            'source' => 'email',
            'notification' => get_class($notification),
            'dedupe_key' => $dedupeKey,
            'email_status' => $status,
        ];
        if ($actionUrl) {
            $data['action_url'] = $actionUrl;
        }

        return [
            'title' => $title ?: 'Notification',
            'message' => $message ?: 'Email en file d\'attente.',
            'action_url' => $actionUrl,
            'category' => NotificationPreferenceService::CATEGORY_EMAILS_MIRROR,
            'data' => $data,
        ];
    }

    private static function buildDedupeKey(Notification $notification, string $title, string $message, ?string $actionUrl): string
    {
        $source = implode('|', [
            get_class($notification),
            trim($title),
            trim($message),
            trim((string) $actionUrl),
        ]);

        return hash('sha256', $source);
    }

    private static function shouldSkipDuplicate(User $user, string $dedupeKey): bool
    {
        return $user->notifications()
            ->where('type', EmailMirrorNotification::class)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->where('data->dedupe_key', $dedupeKey)
            ->exists();
    }

    private static function findQueuedNotification(User $user, string $dedupeKey)
    {
        return $user->notifications()
            ->where('type', EmailMirrorNotification::class)
            ->where('created_at', '>=', now()->subDay())
            ->where('data->dedupe_key', $dedupeKey)
            ->where('data->email_status', 'queued')
            ->latest()
            ->first();
    }

    private static function resolveMailMessage(Notification $notification, object $notifiable): ?MailMessage
    {
        if (!method_exists($notification, 'toMail')) {
            return null;
        }

        $mailMessage = $notification->toMail($notifiable);
        if (!$mailMessage instanceof MailMessage) {
            return null;
        }

        return $mailMessage;
    }

    private static function resolveTitle(Notification $notification, ?MailMessage $mailMessage): string
    {
        if (property_exists($notification, 'title')) {
            $title = (string) $notification->title;
            if (trim($title) !== '') {
                return $title;
            }
        }

        $subject = $mailMessage?->subject;
        if (is_string($subject) && trim($subject) !== '') {
            return $subject;
        }

        return 'Notification';
    }

    private static function resolveMessage(Notification $notification, ?MailMessage $mailMessage, string $title): string
    {
        if (property_exists($notification, 'intro')) {
            $intro = (string) $notification->intro;
            if (trim($intro) !== '') {
                return $intro;
            }
        }

        $introLines = $mailMessage?->introLines;
        if (is_array($introLines) && $introLines) {
            $line = (string) ($introLines[0] ?? '');
            if (trim($line) !== '') {
                return $line;
            }
        }

        $fallback = trim($title) !== '' ? "Email envoye: {$title}" : 'Email envoye.';

        return $fallback;
    }

    private static function resolveActionUrl(Notification $notification, ?MailMessage $mailMessage): ?string
    {
        if (property_exists($notification, 'actionUrl')) {
            $actionUrl = (string) $notification->actionUrl;
            if (trim($actionUrl) !== '') {
                return $actionUrl;
            }
        }

        if (property_exists($notification, 'action_url')) {
            $actionUrl = (string) $notification->action_url;
            if (trim($actionUrl) !== '') {
                return $actionUrl;
            }
        }

        $mailActionUrl = $mailMessage?->actionUrl;
        if (is_string($mailActionUrl) && trim($mailActionUrl) !== '') {
            return $mailActionUrl;
        }

        return null;
    }
}
