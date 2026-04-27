<?php

namespace App\Actions\Leads;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AnonymizeLeadRequestAction
{
    /**
     * @var array<int, string>
     */
    private const SAFE_META_KEYS = [
        'intake_source',
        'request_type',
        'contact_consent',
        'marketing_consent',
        'budget',
        'lead_stage',
        'intent_tags',
        'suggested_service_ids',
        'services_sur_devis',
        'source_kind',
        'source_direction',
        'source_campaign_direction',
        'source_tracking_origin',
        'source_campaign_id',
        'source_campaign_name',
        'source_campaign_run_id',
        'source_campaign_recipient_id',
        'source_prospect_id',
        'source_prospect_batch_id',
        'source_utm_source',
        'source_utm_medium',
        'source_utm_campaign',
        'source_utm_term',
        'source_utm_content',
    ];

    /**
     * @var array<int, string>
     */
    private const SAFE_ACTIVITY_PROPERTY_KEYS = [
        'activity_source',
        'assigned_team_member_id',
        'channel',
        'from',
        'logged_via',
        'meeting_at',
        'media_deleted_count',
        'next_follow_up_at',
        'note_id',
        'notes_scrubbed_count',
        'quick_action',
        'quote_id',
        'sales_activity_action',
        'sales_activity_type',
        'service_type',
        'source_id',
        'status',
        'target_id',
        'tasks_detached_count',
        'to',
    ];

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(LeadRequest $lead, User $actor, array $validated = []): LeadRequest
    {
        $lead->loadMissing('quote');

        if (! $lead->isArchived()) {
            throw ValidationException::withMessages([
                'lead' => ['Prospects must be archived before they can be anonymized.'],
            ]);
        }

        if ($lead->isAnonymized()) {
            throw ValidationException::withMessages([
                'lead' => ['This prospect has already been anonymized.'],
            ]);
        }

        if ($lead->customer_id !== null || $lead->quote !== null) {
            throw ValidationException::withMessages([
                'lead' => ['Prospects linked to a customer or quote require a dedicated retention workflow before anonymization.'],
            ]);
        }

        $reason = trim((string) ($validated['anonymization_reason'] ?? ''));
        $mediaFiles = $lead->media()->get(['id', 'path']);
        $deletedMediaCount = $mediaFiles->count();
        $mediaPaths = $mediaFiles
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->values()
            ->all();

        $scrubbedNotesCount = 0;
        $detachedTasksCount = 0;

        DB::transaction(function () use (
            $lead,
            $actor,
            $reason,
            $mediaPaths,
            $deletedMediaCount,
            &$scrubbedNotesCount,
            &$detachedTasksCount
        ): void {
            $scrubbedNotesCount = $lead->notes()->update([
                'body' => '[Anonymized prospect note]',
            ]);

            $lead->media()->delete();

            $tasks = $lead->tasks()->get();
            $detachedTasksCount = $tasks->count();
            $tasks->each(function (Task $task): void {
                $task->update([
                    'request_id' => null,
                    'customer_id' => null,
                    'title' => 'Anonymized prospect task #'.$task->id,
                    'description' => null,
                ]);
            });

            $lead->update([
                'customer_id' => null,
                'external_customer_id' => null,
                'title' => null,
                'description' => null,
                'contact_name' => null,
                'contact_email' => null,
                'contact_phone' => null,
                'country' => null,
                'state' => null,
                'city' => null,
                'street1' => null,
                'street2' => null,
                'postal_code' => null,
                'lat' => null,
                'lng' => null,
                'next_follow_up_at' => null,
                'lost_reason' => null,
                'archive_reason' => null,
                'last_activity_at' => now(),
                'meta' => $this->buildSanitizedMeta(
                    $lead,
                    $actor,
                    $reason !== '' ? $reason : null,
                    $scrubbedNotesCount,
                    $deletedMediaCount,
                    $detachedTasksCount
                ),
            ]);

            $this->scrubActivityLogs($lead);

            ActivityLog::record($actor, $lead, 'anonymized', [
                'archived_at' => optional($lead->archived_at)->toIso8601String(),
                'notes_scrubbed_count' => $scrubbedNotesCount,
                'media_deleted_count' => $deletedMediaCount,
                'tasks_detached_count' => $detachedTasksCount,
            ], 'Prospect anonymized');

            if ($mediaPaths !== []) {
                DB::afterCommit(function () use ($mediaPaths): void {
                    foreach ($mediaPaths as $path) {
                        if (Storage::disk('public')->exists($path)) {
                            Storage::disk('public')->delete($path);
                        }
                    }
                });
            }
        });

        return $lead->fresh(['assignee.user', 'customer', 'quote', 'archivedBy']) ?? $lead;
    }

    private function buildSanitizedMeta(
        LeadRequest $lead,
        User $actor,
        ?string $reason,
        int $scrubbedNotesCount,
        int $deletedMediaCount,
        int $detachedTasksCount
    ): ?array {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $preserved = array_filter(
            Arr::only($meta, self::SAFE_META_KEYS),
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        );

        $preserved['privacy'] = array_filter([
            'anonymized_at' => now()->toIso8601String(),
            'anonymized_by_user_id' => $actor->id,
            'anonymization_reason' => $reason,
            'had_contact_name' => filled($lead->contact_name),
            'had_contact_email' => filled($lead->contact_email),
            'had_contact_phone' => filled($lead->contact_phone),
            'had_description' => filled($lead->description),
            'had_address' => collect([
                $lead->street1,
                $lead->street2,
                $lead->city,
                $lead->state,
                $lead->postal_code,
                $lead->country,
            ])->filter()->isNotEmpty(),
            'contact_email_sha1' => $this->hashEmail($lead->contact_email),
            'contact_phone_sha1' => $this->hashPhone($lead->contact_phone),
            'notes_scrubbed_count' => $scrubbedNotesCount,
            'media_deleted_count' => $deletedMediaCount,
            'tasks_detached_count' => $detachedTasksCount,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $preserved !== [] ? $preserved : null;
    }

    private function hashEmail(?string $value): ?string
    {
        $email = Str::lower(trim((string) $value));

        return $email !== '' ? sha1($email) : null;
    }

    private function hashPhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? sha1($digits) : null;
    }

    private function scrubActivityLogs(LeadRequest $lead): void
    {
        ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->getKey())
            ->get()
            ->each(function (ActivityLog $activity): void {
                $activity->timestamps = false;
                $activity->forceFill([
                    'description' => $this->safeActivityDescription($activity),
                    'properties' => $this->safeActivityProperties($activity),
                ])->save();
                $activity->timestamps = true;
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeActivityProperties(ActivityLog $activity): ?array
    {
        $properties = is_array($activity->properties) ? $activity->properties : [];
        $sanitized = array_filter(
            Arr::only($properties, self::SAFE_ACTIVITY_PROPERTY_KEYS),
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        );

        return $sanitized !== [] ? $sanitized : null;
    }

    private function safeActivityDescription(ActivityLog $activity): string
    {
        $salesActivity = SalesActivityTaxonomy::definition($activity->action);
        if (is_array($salesActivity) && filled($salesActivity['label'] ?? null)) {
            return Str::limit((string) $salesActivity['label'], 255);
        }

        return match ($activity->action) {
            'created' => 'Prospect created',
            'updated', 'bulk_updated' => 'Prospect updated',
            'note_added' => 'Prospect note added',
            'merged' => 'Prospect merged',
            'merged_into' => 'Prospect merged into another',
            'converted' => 'Prospect converted',
            'archived' => 'Prospect archived',
            'restored' => 'Prospect restored',
            'deleted' => 'Prospect deleted',
            'anonymized' => 'Prospect anonymized',
            default => 'Prospect activity',
        };
    }
}
