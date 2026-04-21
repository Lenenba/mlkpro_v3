<?php

namespace App\Services\CRM\Connectors;

class OutlookConnectorAdapter extends AbstractCrmConnectorAdapter
{
    public function key(): string
    {
        return 'outlook';
    }

    public function label(): string
    {
        return 'Outlook';
    }

    public function supportsMessageEvents(): bool
    {
        return true;
    }

    public function supportsMeetingEvents(): bool
    {
        return true;
    }

    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'auth_strategy' => 'oauth',
            'families' => ['message', 'meeting'],
            'supports_message_events' => true,
            'supports_meeting_events' => true,
            'capabilities' => [
                'message_email_received',
                'message_email_sent',
                'message_email_failed',
                'message_email_retry_scheduled',
                'meeting_scheduled',
                'meeting_completed',
            ],
            'short_description' => 'Connector-ready Outlook adapter for CRM message and meeting events.',
            'scopes' => [
                'mail.read',
                'mail.send',
                'calendars.read',
            ],
        ];
    }

    public function normalizeMessageEvent(string $event, array $payload = []): array
    {
        $normalizedEvent = strtolower(trim($event));

        [$action, $direction, $description] = match ($normalizedEvent) {
            'received' => ['message_email_received', 'inbound', 'Email received via Outlook'],
            'sent' => ['message_email_sent', 'outbound', 'Email sent via Outlook'],
            'failed' => ['message_email_failed', 'outbound', 'Email failed via Outlook'],
            'retry_scheduled' => ['message_email_retry_scheduled', 'outbound', 'Email retry scheduled via Outlook'],
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
                'provider_message_id' => $this->resolveFirstString($payload, ['provider_message_id', 'outlook_message_id']),
                'external_message_id' => $this->resolveFirstString($payload, ['external_message_id', 'conversation_id']),
                'scheduled_for' => $this->resolveFirstString($payload, ['scheduled_for', 'retry_at']),
                'retry_attempt' => $this->resolveNullableInt($payload['retry_attempt'] ?? $payload['attempt'] ?? null),
                'delay_minutes' => $this->resolveNullableInt($payload['delay_minutes'] ?? null),
            ]),
        ];
    }

    public function normalizeMeetingEvent(string $event, array $payload = []): array
    {
        $normalizedEvent = strtolower(trim($event));

        [$action, $description] = match ($normalizedEvent) {
            'scheduled' => ['meeting_scheduled', 'Meeting scheduled via Outlook'],
            'completed' => ['meeting_completed', 'Meeting completed via Outlook'],
            default => $this->unsupported('meeting', $event),
        };

        return [
            'action' => $action,
            'description' => $description,
            'properties' => $this->filtered([
                'provider' => $this->key(),
                'source' => $this->resolveString($payload['source'] ?? null) ?? 'calendar_sync',
                'event' => $this->resolveString($payload['event'] ?? null) ?? $normalizedEvent,
                'external_meeting_id' => $this->resolveFirstString($payload, ['external_meeting_id', 'event_id']),
                'start_at' => $this->resolveFirstString($payload, ['start_at', 'scheduled_for', 'meeting_at']),
                'end_at' => $this->resolveFirstString($payload, ['end_at', 'ended_at']),
                'completed_at' => $this->resolveFirstString($payload, ['completed_at', 'ended_at']),
                'location' => $this->resolveFirstString($payload, ['location', 'meeting_location']),
                'conference_url' => $this->resolveFirstString($payload, ['conference_url', 'meeting_url', 'video_url']),
                'organizer_email' => $this->resolveString($payload['organizer_email'] ?? null),
                'all_day' => $this->resolveBoolean($payload['all_day'] ?? $payload['is_all_day'] ?? null),
            ]),
        ];
    }
}
