<?php

namespace App\Listeners;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\EmailMirrorNotification;
use App\Services\PushNotificationService;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

class SendEmailMirrorNotifications
{
    public function __construct(private PushNotificationService $push)
    {
    }

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $recipients = $this->resolveRecipients($event->notifiable);
        if ($recipients->isEmpty()) {
            return;
        }

        $payload = $this->buildPayload($event);
        if (!$payload) {
            return;
        }

        foreach ($recipients as $user) {
            $user->notify(new EmailMirrorNotification(
                $payload['title'],
                $payload['message'],
                $payload['action_url'] ?? null,
                $payload['category'] ?? null,
                $payload['data'] ?? []
            ));
        }

        $userIds = $recipients->pluck('id')->unique()->values()->all();
        if (!$userIds) {
            return;
        }

        $this->push->sendToUsers($userIds, [
            'title' => $payload['title'],
            'body' => $payload['message'],
            'data' => $payload['data'] ?? [],
        ]);
    }

    private function resolveRecipients(object $notifiable): Collection
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

    private function buildPayload(NotificationSent $event): array
    {
        $notification = $event->notification;
        $mailMessage = $this->resolveMailMessage($notification, $event->notifiable);

        $title = $this->resolveTitle($notification, $mailMessage);
        $message = $this->resolveMessage($notification, $mailMessage, $title);
        $actionUrl = $this->resolveActionUrl($notification, $mailMessage);

        $data = [
            'source' => 'email',
            'notification' => get_class($notification),
        ];
        if ($actionUrl) {
            $data['action_url'] = $actionUrl;
        }

        return [
            'title' => $title ?: 'Notification',
            'message' => $message ?: 'Email envoye.',
            'action_url' => $actionUrl,
            'category' => 'system',
            'data' => $data,
        ];
    }

    private function resolveMailMessage(object $notification, object $notifiable): ?MailMessage
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

    private function resolveTitle(object $notification, ?MailMessage $mailMessage): string
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

    private function resolveMessage(object $notification, ?MailMessage $mailMessage, string $title): string
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

    private function resolveActionUrl(object $notification, ?MailMessage $mailMessage): ?string
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
