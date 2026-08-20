<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DemoActionNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $type = trim((string) ($this->payload['type'] ?? $this->payload['category'] ?? 'system'));
        $type = $type !== '' ? $type : 'system';
        $severity = trim((string) ($this->payload['severity'] ?? 'info'));

        return [
            'title' => (string) ($this->payload['title'] ?? 'Demo action'),
            'message' => (string) ($this->payload['message'] ?? ''),
            'action_url' => $this->payload['action_url'] ?? null,
            'type' => $type,
            'category' => $type,
            'severity' => $severity !== '' ? $severity : 'info',
            'reference' => $this->payload['reference'] ?? null,
            ...(filled($this->payload['scenario_key'] ?? null)
                ? ['scenario_key' => (string) $this->payload['scenario_key']]
                : []),
        ];
    }
}
