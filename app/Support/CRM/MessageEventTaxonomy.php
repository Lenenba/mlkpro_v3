<?php

namespace App\Support\CRM;

final class MessageEventTaxonomy
{
    public const FAMILY = 'message_event';

    public const CHANNEL_EMAIL = 'email';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'message_email_sent' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_sent',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'sent',
                'label' => 'Email sent',
                'icon' => 'mail',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => true,
                'legacy' => false,
            ],
            'message_email_received' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_received',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_INBOUND,
                'delivery_state' => 'received',
                'label' => 'Email received',
                'icon' => 'inbox',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => true,
                'legacy' => false,
            ],
            'message_email_failed' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_failed',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'failed',
                'label' => 'Email failed',
                'icon' => 'alert-circle',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => false,
                'legacy' => false,
            ],
            'message_email_retry_scheduled' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_retry_scheduled',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'retry_scheduled',
                'label' => 'Email retry scheduled',
                'icon' => 'clock',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => false,
                'legacy' => false,
            ],

            // Legacy actions already produced by the app that Phase 5 will absorb.
            'email_sent' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_sent',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'sent',
                'label' => 'Email sent',
                'icon' => 'mail',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => true,
                'legacy' => true,
            ],
            'email_failed' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_failed',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'failed',
                'label' => 'Email failed',
                'icon' => 'alert-circle',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => false,
                'legacy' => true,
            ],
            'lead_email_failed' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_failed',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'failed',
                'label' => 'Lead email failed',
                'icon' => 'alert-circle',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => false,
                'legacy' => true,
            ],
            'lead_email_retry_scheduled' => [
                'family' => self::FAMILY,
                'event_key' => 'message_email_retry_scheduled',
                'channel' => self::CHANNEL_EMAIL,
                'direction' => self::DIRECTION_OUTBOUND,
                'delivery_state' => 'retry_scheduled',
                'label' => 'Lead email retry scheduled',
                'icon' => 'clock',
                'timeline_variant' => 'email',
                'counts_as_touchpoint' => false,
                'legacy' => true,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function actions(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(?string $action): ?array
    {
        if (! $action) {
            return null;
        }

        return self::definitions()[$action] ?? null;
    }

    public static function isMessageEvent(?string $action): bool
    {
        return self::definition($action) !== null;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    public static function present(?string $action, array $properties = []): ?array
    {
        $definition = self::definition($action);

        if ($definition === null) {
            return null;
        }

        return [
            'family' => $definition['family'],
            'action' => $action,
            'event_key' => $definition['event_key'],
            'channel' => $definition['channel'],
            'direction' => $definition['direction'],
            'delivery_state' => $definition['delivery_state'],
            'label' => $definition['label'],
            'icon' => $definition['icon'],
            'timeline_variant' => $definition['timeline_variant'],
            'legacy' => (bool) ($definition['legacy'] ?? false),
            'counts_as_touchpoint' => (bool) ($definition['counts_as_touchpoint'] ?? false),
            'email' => self::resolveString($properties['email'] ?? null),
            'source' => self::resolveString($properties['source'] ?? null),
            'event' => self::resolveString($properties['event'] ?? null),
            'notification' => self::resolveString($properties['notification'] ?? null),
            'message_id' => self::resolveString($properties['message_id'] ?? null),
            'provider_message_id' => self::resolveString($properties['provider_message_id'] ?? null),
            'provider' => self::resolveString($properties['provider'] ?? null),
            'external_message_id' => self::resolveString($properties['external_message_id'] ?? null),
            'quote_id' => self::resolveNullableInt($properties['quote_id'] ?? null),
            'request_id' => self::resolveNullableInt($properties['request_id'] ?? null),
            'customer_id' => self::resolveNullableInt($properties['customer_id'] ?? null),
            'retry_attempt' => self::resolveRetryAttempt($properties),
            'delay_minutes' => self::resolveNullableInt($properties['delay_minutes'] ?? null),
            'scheduled_for' => self::resolveScheduledFor($properties),
            'assistant' => (bool) ($properties['assistant'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function resolveRetryAttempt(array $properties): ?int
    {
        foreach (['retry_attempt', 'attempt'] as $key) {
            $attempt = self::resolveNullableInt($properties[$key] ?? null);

            if ($attempt !== null) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function resolveScheduledFor(array $properties): ?string
    {
        foreach (['scheduled_for', 'retry_at'] as $key) {
            $value = self::resolveString($properties[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function resolveNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function resolveString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
