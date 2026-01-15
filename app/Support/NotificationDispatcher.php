<?php

namespace App\Support;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationDispatcher
{
    public static function send($notifiable, Notification $notification, array $context = []): bool
    {
        try {
            if (config('queue.default', 'sync') === 'sync') {
                NotificationFacade::sendNow($notifiable, $notification);
            } else {
                $notifiable->notify($notification);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Notification dispatch failed.', array_merge([
                'notification' => get_class($notification),
                'notifiable_type' => is_object($notifiable) ? get_class($notifiable) : gettype($notifiable),
                'notifiable_id' => is_object($notifiable) && property_exists($notifiable, 'id')
                    ? $notifiable->id
                    : null,
                'error' => $e->getMessage(),
            ], $context));

            return false;
        }
    }

    public static function sendToMail(string $email, Notification $notification, array $context = []): bool
    {
        $notifiable = NotificationFacade::route('mail', $email);

        return self::send($notifiable, $notification, array_merge($context, [
            'email' => $email,
        ]));
    }
}
