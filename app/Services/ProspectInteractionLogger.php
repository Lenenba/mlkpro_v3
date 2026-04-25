<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\LeadMedia;
use App\Models\LeadNote;
use App\Models\ProspectInteraction;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProspectInteractionLogger
{
    public const TYPE_NOTE = 'note';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_CALL = 'call';

    public const TYPE_CALL_OUTCOME = 'call_outcome';

    public const TYPE_NEXT_ACTION = 'next_action';

    public const TYPE_MEETING = 'meeting';

    public const TYPE_EMAIL = 'email';

    public const TYPE_SYSTEM = 'system';

    public function recordNoteAdded(LeadRequest $lead, ?User $actor, LeadNote $note): ProspectInteraction
    {
        return $this->persist(
            $lead,
            $actor,
            [
                'type' => self::TYPE_NOTE,
                'description' => $note->body,
                'metadata' => [
                    'source' => 'lead_note',
                ],
            ],
            $note,
            $note->created_at,
        );
    }

    public function recordMediaAdded(LeadRequest $lead, ?User $actor, LeadMedia $media): ProspectInteraction
    {
        return $this->persist(
            $lead,
            $actor,
            [
                'type' => self::TYPE_DOCUMENT,
                'description' => $media->original_name ?: $media->path,
                'attachment_name' => $media->original_name ?: basename((string) $media->path),
                'attachment_path' => $media->path,
                'attachment_mime' => $media->mime,
                'attachment_size' => $media->size,
                'metadata' => array_filter([
                    'source' => 'lead_media',
                    'meta' => $media->meta,
                ], static fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ],
            $media,
            $media->created_at,
        );
    }

    public function recordActivity(LeadRequest $lead, ?User $actor, ActivityLog $activity): ProspectInteraction
    {
        $payload = $this->payloadFromActivity($activity);

        $interaction = $this->persist(
            $lead,
            $actor,
            $payload,
            $activity,
            $activity->created_at,
        );

        $this->syncLeadStateFromActivity($lead, $activity, $payload);

        return $interaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromActivity(ActivityLog $activity): array
    {
        $properties = is_array($activity->properties) ? $activity->properties : [];
        $salesActivity = $activity->sales_activity;
        $messageEvent = $activity->message_event;
        $meetingEvent = $activity->meeting_event;

        if (is_array($salesActivity)) {
            $nextActionAt = $this->parseNullableCarbon(
                $properties['next_follow_up_at'] ?? $properties['meeting_at'] ?? null
            );

            return [
                'type' => (string) ($salesActivity['type'] ?? self::TYPE_SYSTEM),
                'description' => $activity->description,
                'next_action_at' => $nextActionAt,
                'next_action_label' => $nextActionAt
                    ? (string) ($activity->description ?: ($salesActivity['label'] ?? ''))
                    : null,
                'metadata' => array_filter([
                    'source' => 'sales_activity',
                    'activity_key' => $salesActivity['activity_key'] ?? null,
                    'timeline_variant' => $salesActivity['timeline_variant'] ?? null,
                    'icon' => $salesActivity['icon'] ?? null,
                    'outcome' => $salesActivity['outcome'] ?? null,
                    'quick_action' => $properties['quick_action'] ?? null,
                    'note' => $properties['note'] ?? null,
                    'opens_next_action' => ($salesActivity['opens_next_action'] ?? false) ? true : null,
                    'closes_next_action' => ($salesActivity['closes_next_action'] ?? false) ? true : null,
                ], static fn ($value) => $value !== null && $value !== '' && $value !== []),
            ];
        }

        if (is_array($messageEvent)) {
            $nextActionAt = $this->parseNullableCarbon($messageEvent['scheduled_for'] ?? null);

            return [
                'type' => self::TYPE_EMAIL,
                'description' => $activity->description,
                'next_action_at' => $nextActionAt,
                'next_action_label' => $nextActionAt
                    ? (string) ($activity->description ?: ($messageEvent['label'] ?? ''))
                    : null,
                'metadata' => array_filter([
                    'source' => 'message_event',
                    'event_key' => $messageEvent['event_key'] ?? null,
                    'channel' => $messageEvent['channel'] ?? null,
                    'direction' => $messageEvent['direction'] ?? null,
                    'delivery_state' => $messageEvent['delivery_state'] ?? null,
                    'email' => $messageEvent['email'] ?? null,
                    'provider' => $messageEvent['provider'] ?? null,
                    'retry_attempt' => $messageEvent['retry_attempt'] ?? null,
                    'assistant' => ($messageEvent['assistant'] ?? false) ? true : null,
                ], static fn ($value) => $value !== null && $value !== '' && $value !== []),
            ];
        }

        if (is_array($meetingEvent)) {
            $nextActionAt = $this->parseNullableCarbon($meetingEvent['start_at'] ?? null);

            return [
                'type' => self::TYPE_MEETING,
                'description' => $activity->description,
                'next_action_at' => $nextActionAt,
                'next_action_label' => $nextActionAt
                    ? (string) ($activity->description ?: ($meetingEvent['label'] ?? ''))
                    : null,
                'metadata' => array_filter([
                    'source' => 'meeting_event',
                    'event_key' => $meetingEvent['event_key'] ?? null,
                    'lifecycle_state' => $meetingEvent['lifecycle_state'] ?? null,
                    'provider' => $meetingEvent['provider'] ?? null,
                    'location' => $meetingEvent['location'] ?? null,
                    'conference_url' => $meetingEvent['conference_url'] ?? null,
                    'all_day' => ($meetingEvent['all_day'] ?? false) ? true : null,
                ], static fn ($value) => $value !== null && $value !== '' && $value !== []),
            ];
        }

        return [
            'type' => self::TYPE_SYSTEM,
            'description' => $activity->description ?: $activity->action,
            'metadata' => [
                'source' => 'activity_log',
                'action' => $activity->action,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncLeadStateFromActivity(LeadRequest $lead, ActivityLog $activity, array $payload): void
    {
        $updates = [];
        $occurredAt = $activity->created_at instanceof CarbonInterface
            ? Carbon::instance($activity->created_at)
            : now();
        $currentLastActivity = $lead->last_activity_at instanceof CarbonInterface
            ? Carbon::instance($lead->last_activity_at)
            : null;

        if ($currentLastActivity === null || $occurredAt->greaterThan($currentLastActivity)) {
            $updates['last_activity_at'] = $occurredAt;
        }

        if (is_array($activity->sales_activity)) {
            $nextActionAt = $payload['next_action_at'] ?? null;

            if ($nextActionAt instanceof CarbonInterface) {
                $updates['next_follow_up_at'] = Carbon::instance($nextActionAt);
            } elseif (($activity->sales_activity['closes_next_action'] ?? false) === true) {
                $updates['next_follow_up_at'] = null;
            }
        }

        if ($updates === []) {
            return;
        }

        $lead->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(
        LeadRequest $lead,
        ?User $actor,
        array $attributes,
        Model $source,
        mixed $occurredAt = null,
    ): ProspectInteraction {
        $interaction = ProspectInteraction::query()
            ->where('request_id', $lead->id)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();

        if (! $interaction) {
            $interaction = new ProspectInteraction([
                'request_id' => $lead->id,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
            ]);
        }

        $interaction->fill(array_merge($attributes, [
            'request_id' => $lead->id,
            'user_id' => $actor?->id,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]));

        $interaction->save();

        $timestamp = $this->parseNullableCarbon($occurredAt);
        if ($timestamp instanceof CarbonInterface) {
            $interaction->timestamps = false;
            $interaction->forceFill([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->save();
            $interaction->timestamps = true;
        }

        return $interaction->fresh(['user:id,name']) ?? $interaction->load('user:id,name');
    }

    private function parseNullableCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
