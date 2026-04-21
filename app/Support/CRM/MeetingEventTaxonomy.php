<?php

namespace App\Support\CRM;

final class MeetingEventTaxonomy
{
    public const FAMILY = 'meeting_event';

    public const LIFECYCLE_SCHEDULED = 'scheduled';
    public const LIFECYCLE_COMPLETED = 'completed';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'meeting_scheduled' => [
                'family' => self::FAMILY,
                'event_key' => 'meeting_scheduled',
                'lifecycle_state' => self::LIFECYCLE_SCHEDULED,
                'label' => 'Meeting scheduled',
                'icon' => 'calendar',
                'timeline_variant' => 'meeting',
                'counts_as_touchpoint' => false,
                'legacy' => false,
            ],
            'meeting_completed' => [
                'family' => self::FAMILY,
                'event_key' => 'meeting_completed',
                'lifecycle_state' => self::LIFECYCLE_COMPLETED,
                'label' => 'Meeting completed',
                'icon' => 'users',
                'timeline_variant' => 'meeting',
                'counts_as_touchpoint' => true,
                'legacy' => false,
            ],

            // Legacy CRM sales meeting actions absorbed by Phase 5.
            'sales_meeting_scheduled' => [
                'family' => self::FAMILY,
                'event_key' => 'meeting_scheduled',
                'lifecycle_state' => self::LIFECYCLE_SCHEDULED,
                'label' => 'Sales meeting scheduled',
                'icon' => 'calendar',
                'timeline_variant' => 'meeting',
                'counts_as_touchpoint' => false,
                'legacy' => true,
            ],
            'sales_meeting_completed' => [
                'family' => self::FAMILY,
                'event_key' => 'meeting_completed',
                'lifecycle_state' => self::LIFECYCLE_COMPLETED,
                'label' => 'Sales meeting completed',
                'icon' => 'users',
                'timeline_variant' => 'meeting',
                'counts_as_touchpoint' => true,
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

    public static function isMeetingEvent(?string $action): bool
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
            'lifecycle_state' => $definition['lifecycle_state'],
            'label' => $definition['label'],
            'icon' => $definition['icon'],
            'timeline_variant' => $definition['timeline_variant'],
            'legacy' => (bool) ($definition['legacy'] ?? false),
            'counts_as_touchpoint' => (bool) ($definition['counts_as_touchpoint'] ?? false),
            'provider' => self::resolveString($properties['provider'] ?? $properties['calendar_provider'] ?? null),
            'source' => self::resolveString($properties['source'] ?? null),
            'external_meeting_id' => self::resolveString($properties['external_meeting_id'] ?? $properties['meeting_id'] ?? null),
            'start_at' => self::resolveStartAt($properties),
            'end_at' => self::resolveString($properties['end_at'] ?? $properties['ended_at'] ?? null),
            'completed_at' => self::resolveString($properties['completed_at'] ?? null),
            'location' => self::resolveString($properties['location'] ?? $properties['meeting_location'] ?? null),
            'conference_url' => self::resolveString($properties['conference_url'] ?? $properties['meeting_url'] ?? $properties['video_url'] ?? null),
            'organizer_email' => self::resolveString($properties['organizer_email'] ?? null),
            'customer_id' => self::resolveNullableInt($properties['customer_id'] ?? null),
            'request_id' => self::resolveNullableInt($properties['request_id'] ?? null),
            'quote_id' => self::resolveNullableInt($properties['quote_id'] ?? null),
            'all_day' => (bool) ($properties['all_day'] ?? $properties['is_all_day'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function resolveStartAt(array $properties): ?string
    {
        foreach (['start_at', 'scheduled_for', 'meeting_at'] as $key) {
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
