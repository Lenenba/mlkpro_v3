<?php

namespace App\Support\CRM;

final class SalesActivityTaxonomy
{
    public const FAMILY = 'sales_activity';

    public const TYPE_NOTE = 'note';

    public const TYPE_CALL = 'call';

    public const TYPE_CALL_OUTCOME = 'call_outcome';

    public const TYPE_NEXT_ACTION = 'next_action';

    public const TYPE_MEETING = 'meeting';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'sales_note_added' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NOTE,
                'activity_key' => 'sales_note_added',
                'label' => 'Commercial note added',
                'icon' => 'note',
                'timeline_variant' => 'note',
                'outcome' => 'logged',
                'counts_as_touchpoint' => true,
                'opens_next_action' => false,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_call_logged' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_CALL,
                'activity_key' => 'sales_call_logged',
                'label' => 'Call logged',
                'icon' => 'phone',
                'timeline_variant' => 'call',
                'outcome' => 'completed',
                'counts_as_touchpoint' => true,
                'opens_next_action' => false,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_call_no_answer' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_CALL_OUTCOME,
                'activity_key' => 'sales_call_no_answer',
                'label' => 'No answer',
                'icon' => 'phone-off',
                'timeline_variant' => 'call_result',
                'outcome' => 'no_answer',
                'counts_as_touchpoint' => true,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_call_quote_discussed' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_CALL_OUTCOME,
                'activity_key' => 'sales_call_quote_discussed',
                'label' => 'Quote discussed',
                'icon' => 'phone',
                'timeline_variant' => 'call_result',
                'outcome' => 'quote_discussed',
                'counts_as_touchpoint' => true,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_call_to_recontact' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_CALL_OUTCOME,
                'activity_key' => 'sales_call_to_recontact',
                'label' => 'To recontact',
                'icon' => 'refresh',
                'timeline_variant' => 'call_result',
                'outcome' => 'recontact',
                'counts_as_touchpoint' => true,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_next_action_scheduled' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_scheduled',
                'label' => 'Next action scheduled',
                'icon' => 'calendar',
                'timeline_variant' => 'next_action',
                'outcome' => 'scheduled',
                'counts_as_touchpoint' => false,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_next_action_completed' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_completed',
                'label' => 'Next action completed',
                'icon' => 'check-circle',
                'timeline_variant' => 'next_action',
                'outcome' => 'completed',
                'counts_as_touchpoint' => true,
                'opens_next_action' => false,
                'closes_next_action' => true,
                'legacy' => false,
            ],
            'sales_next_action_cleared' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_cleared',
                'label' => 'Next action cleared',
                'icon' => 'x-circle',
                'timeline_variant' => 'next_action',
                'outcome' => 'cleared',
                'counts_as_touchpoint' => false,
                'opens_next_action' => false,
                'closes_next_action' => true,
                'legacy' => false,
            ],
            'sales_meeting_scheduled' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_MEETING,
                'activity_key' => 'sales_meeting_scheduled',
                'label' => 'Meeting scheduled',
                'icon' => 'calendar',
                'timeline_variant' => 'meeting',
                'outcome' => 'scheduled',
                'counts_as_touchpoint' => false,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => false,
            ],
            'sales_meeting_completed' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_MEETING,
                'activity_key' => 'sales_meeting_completed',
                'label' => 'Meeting completed',
                'icon' => 'users',
                'timeline_variant' => 'meeting',
                'outcome' => 'completed',
                'counts_as_touchpoint' => true,
                'opens_next_action' => false,
                'closes_next_action' => true,
                'legacy' => false,
            ],

            // Legacy actions already produced by the CRM that Phase 4 will absorb.
            'contacted' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_CALL_OUTCOME,
                'activity_key' => 'sales_call_logged',
                'label' => 'Lead contacted',
                'icon' => 'phone',
                'timeline_variant' => 'call_result',
                'outcome' => 'contacted',
                'counts_as_touchpoint' => true,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => true,
            ],
            'lead_call_requested' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_scheduled',
                'label' => 'Call requested',
                'icon' => 'phone-incoming',
                'timeline_variant' => 'next_action',
                'outcome' => 'call_requested',
                'counts_as_touchpoint' => false,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => true,
            ],
            'quote_follow_up_scheduled' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_scheduled',
                'label' => 'Quote follow-up scheduled',
                'icon' => 'calendar',
                'timeline_variant' => 'next_action',
                'outcome' => 'scheduled',
                'counts_as_touchpoint' => false,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => true,
            ],
            'quote_follow_up_completed' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_completed',
                'label' => 'Quote follow-up completed',
                'icon' => 'check-circle',
                'timeline_variant' => 'next_action',
                'outcome' => 'completed',
                'counts_as_touchpoint' => true,
                'opens_next_action' => false,
                'closes_next_action' => true,
                'legacy' => true,
            ],
            'quote_follow_up_completed_and_rescheduled' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_completed',
                'label' => 'Quote follow-up completed and rescheduled',
                'icon' => 'calendar-refresh',
                'timeline_variant' => 'next_action',
                'outcome' => 'completed_and_rescheduled',
                'counts_as_touchpoint' => true,
                'opens_next_action' => true,
                'closes_next_action' => true,
                'legacy' => true,
            ],
            'quote_follow_up_cleared' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_cleared',
                'label' => 'Quote follow-up cleared',
                'icon' => 'x-circle',
                'timeline_variant' => 'next_action',
                'outcome' => 'cleared',
                'counts_as_touchpoint' => false,
                'opens_next_action' => false,
                'closes_next_action' => true,
                'legacy' => true,
            ],
            'quote_follow_up_task_created' => [
                'family' => self::FAMILY,
                'type' => self::TYPE_NEXT_ACTION,
                'activity_key' => 'sales_next_action_scheduled',
                'label' => 'Recovery task created from quote',
                'icon' => 'check-square',
                'timeline_variant' => 'next_action',
                'outcome' => 'task_created',
                'counts_as_touchpoint' => false,
                'opens_next_action' => true,
                'closes_next_action' => false,
                'legacy' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function quickActions(): array
    {
        return [
            'call_logged' => [
                'id' => 'call_logged',
                'action' => 'sales_call_logged',
                'label' => 'Appel effectue',
                'type' => self::TYPE_CALL,
                'icon' => 'phone',
            ],
            'no_answer' => [
                'id' => 'no_answer',
                'action' => 'sales_call_no_answer',
                'label' => 'Pas de reponse',
                'type' => self::TYPE_CALL_OUTCOME,
                'icon' => 'phone-off',
            ],
            'callback_tomorrow' => [
                'id' => 'callback_tomorrow',
                'action' => 'sales_next_action_scheduled',
                'label' => 'Rappel demain',
                'type' => self::TYPE_NEXT_ACTION,
                'icon' => 'calendar',
                'default_offset_days' => 1,
                'preset' => 'callback_tomorrow',
            ],
            'quote_discussed' => [
                'id' => 'quote_discussed',
                'action' => 'sales_call_quote_discussed',
                'label' => 'Devis discute',
                'type' => self::TYPE_CALL_OUTCOME,
                'icon' => 'phone',
            ],
            'recontact' => [
                'id' => 'recontact',
                'action' => 'sales_call_to_recontact',
                'label' => 'A recontacter',
                'type' => self::TYPE_CALL_OUTCOME,
                'icon' => 'refresh',
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
     * @return array<int, string>
     */
    public static function manualActions(): array
    {
        return collect(self::definitions())
            ->reject(fn (array $definition) => (bool) ($definition['legacy'] ?? false))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function manualActionDefinitions(): array
    {
        return collect(self::manualActions())
            ->map(function (string $action): ?array {
                $definition = self::definition($action);

                if ($definition === null) {
                    return null;
                }

                return array_merge($definition, [
                    'action' => $action,
                ]);
            })
            ->filter()
            ->values()
            ->all();
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

    public static function isSalesActivity(?string $action): bool
    {
        return self::definition($action) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function quickAction(?string $id): ?array
    {
        if (! $id) {
            return null;
        }

        return self::quickActions()[$id] ?? null;
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
            'activity_key' => $definition['activity_key'],
            'type' => $definition['type'],
            'label' => $definition['label'],
            'icon' => $definition['icon'],
            'timeline_variant' => $definition['timeline_variant'],
            'outcome' => $definition['outcome'],
            'legacy' => (bool) ($definition['legacy'] ?? false),
            'counts_as_touchpoint' => (bool) ($definition['counts_as_touchpoint'] ?? false),
            'opens_next_action' => (bool) ($definition['opens_next_action'] ?? false),
            'closes_next_action' => (bool) ($definition['closes_next_action'] ?? false),
            'due_at' => self::resolveDueAt($properties),
            'task_id' => isset($properties['task_id']) ? (int) $properties['task_id'] : null,
            'task_title' => $properties['task_title'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function resolveDueAt(array $properties): ?string
    {
        foreach (['next_follow_up_at', 'task_due_date', 'scheduled_for', 'meeting_at'] as $key) {
            $value = $properties[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
