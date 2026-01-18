<?php

namespace App\Listeners;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\EmailMirrorNotification;
use App\Notifications\OrderStatusNotification;
use App\Services\NotificationPreferenceService;
use App\Services\PushNotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Collection;

class SendDatabasePushNotifications
{
    public function __construct(
        private PushNotificationService $push,
        private NotificationPreferenceService $preferences
    ) {
    }

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        if ($event->notification instanceof EmailMirrorNotification) {
            return;
        }

        $notifiable = $event->notifiable;
        if (
            $event->notification instanceof OrderStatusNotification
            && $notifiable instanceof User
            && $notifiable->isClient()
        ) {
            return;
        }

        $recipients = $this->resolveRecipients($notifiable);
        if ($recipients->isEmpty()) {
            return;
        }

        $payload = $this->resolvePayload($event, $notifiable);
        if (!$payload) {
            return;
        }

        $category = $payload['category'] ?? NotificationPreferenceService::CATEGORY_SYSTEM;
        $eligible = $recipients->filter(fn (User $user) => $this->preferences->shouldNotify(
            $user,
            $category,
            NotificationPreferenceService::CHANNEL_PUSH
        ));

        if ($eligible->isEmpty()) {
            return;
        }

        $userIds = $eligible->pluck('id')->unique()->values()->all();
        if (!$userIds) {
            return;
        }

        $this->push->sendToUsers($userIds, [
            'title' => $payload['title'],
            'body' => $payload['message'] ?: $payload['title'],
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
                    ? User::query()->select(['id', 'role_id', 'notification_settings'])->find($notifiable->portal_user_id)
                    : null);

            return $portalUser ? collect([$portalUser]) : collect();
        }

        return collect();
    }

    private function resolvePayload(NotificationSent $event, object $notifiable): ?array
    {
        $data = null;
        if ($event->response instanceof DatabaseNotification) {
            $data = $event->response->data;
        }

        if (!$data && method_exists($event->notification, 'toArray')) {
            $data = $event->notification->toArray($notifiable);
        }

        if (!is_array($data)) {
            return null;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if ($title === '' && $message === '') {
            return null;
        }

        $category = $data['category'] ?? NotificationPreferenceService::CATEGORY_SYSTEM;
        $extra = array_filter([
            'action_url' => $data['action_url'] ?? null,
            'sale_id' => $data['sale_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'work_id' => $data['work_id'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'quote_id' => $data['quote_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'category' => $category,
            'source' => 'database',
        ], fn ($value) => $value !== null && $value !== '');

        $payloadData = [];
        if (isset($data['data']) && is_array($data['data'])) {
            $payloadData = $data['data'];
        }

        return [
            'title' => $title !== '' ? $title : 'Notification',
            'message' => $message !== '' ? $message : $title,
            'category' => $category,
            'data' => array_merge($extra, $payloadData),
        ];
    }
}
