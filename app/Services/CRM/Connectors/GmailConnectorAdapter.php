<?php

namespace App\Services\CRM\Connectors;

class GmailConnectorAdapter extends AbstractCrmConnectorAdapter
{
    public function key(): string
    {
        return 'gmail';
    }

    public function label(): string
    {
        return 'Gmail';
    }

    public function supportsMessageEvents(): bool
    {
        return true;
    }

    public function supportsMeetingEvents(): bool
    {
        return false;
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'auth_strategy' => 'oauth',
            'families' => ['message'],
            'supports_message_events' => true,
            'supports_meeting_events' => false,
            'capabilities' => [
                'message_email_received',
                'message_email_sent',
                'message_email_failed',
                'message_email_retry_scheduled',
            ],
            'short_description' => 'Connector-ready Gmail adapter for CRM message events.',
            'scopes' => [
                'gmail.readonly',
                'gmail.send',
            ],
        ];
    }

    public function normalizeMessageEvent(string $event, array $payload = []): array
    {
        $normalizedEvent = strtolower(trim($event));

        [$action, $direction, $description] = match ($normalizedEvent) {
            'received' => ['message_email_received', 'inbound', 'Email received via Gmail'],
            'sent' => ['message_email_sent', 'outbound', 'Email sent via Gmail'],
            'failed' => ['message_email_failed', 'outbound', 'Email failed via Gmail'],
            'retry_scheduled' => ['message_email_retry_scheduled', 'outbound', 'Email retry scheduled via Gmail'],
            default => $this->unsupported('message', $event),
        };

        return [
            'action' => $action,
            'description' => $description,
            'properties' => $this->filtered([
                'provider' => $this->key(),
                'source' => $this->resolveString($payload['source'] ?? null) ?? 'connector_sync',
                'event' => $this->resolveString($payload['event'] ?? null) ?? $normalizedEvent,
                'email' => $this->resolveMessageEmail($direction, $payload),
                'message_id' => $this->resolveFirstString($payload, ['message_id', 'internet_message_id']),
                'provider_message_id' => $this->resolveFirstString($payload, ['provider_message_id', 'gmail_message_id']),
                'external_message_id' => $this->resolveFirstString($payload, ['external_message_id', 'thread_id']),
                'scheduled_for' => $this->resolveFirstString($payload, ['scheduled_for', 'retry_at']),
                'retry_attempt' => $this->resolveNullableInt($payload['retry_attempt'] ?? $payload['attempt'] ?? null),
                'delay_minutes' => $this->resolveNullableInt($payload['delay_minutes'] ?? null),
            ]),
        ];
    }

    public function normalizeMeetingEvent(string $event, array $payload = []): array
    {
        $this->unsupported('meeting', $event);
    }
}
