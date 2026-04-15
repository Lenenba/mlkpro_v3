<?php

namespace App\Services\Campaigns;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectProviderConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

class ProspectProviderPreviewService
{
    public function __construct(
        private readonly ProspectProviderRegistry $registry,
        private readonly ProspectProviderConnectionService $connectionService,
        private readonly ProspectProviderImportGuardService $importGuardService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(User $owner, User $actor, Campaign $campaign, array $payload): array
    {
        if ((int) $campaign->user_id !== (int) $owner->id) {
            abort(404);
        }

        if (! $campaign->prospecting_enabled || $campaign->campaign_direction !== Campaign::DIRECTION_PROSPECTING_OUTBOUND) {
            throw ValidationException::withMessages([
                'campaign' => 'Provider preview is only available for prospecting campaigns.',
            ]);
        }

        $connectionId = (int) ($payload['provider_connection_id'] ?? 0);
        $query = trim((string) ($payload['query'] ?? ''));
        $queryLabel = trim((string) ($payload['query_label'] ?? ''));
        $limit = max(1, min(50, (int) ($payload['limit'] ?? 25)));

        if ($connectionId <= 0) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Select a connected provider first.',
            ]);
        }

        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => 'A provider query or ICP is required.',
            ]);
        }

        $connection = CampaignProspectProviderConnection::query()
            ->where('user_id', $owner->id)
            ->find($connectionId);

        if (! $connection) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'The selected provider connection was not found.',
            ]);
        }

        if (! $connection->is_active || $connection->status !== CampaignProspectProviderConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'The selected provider must be connected before previewing prospects.',
            ]);
        }

        $adapter = $this->registry->adapter($connection->provider_key);
        $providerConnectionPayload = $this->connectionService->payload($connection);
        $queryContext = [
            'query' => $query,
            'query_label' => $queryLabel,
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'provider_key' => $connection->provider_key,
            'provider_label' => $providerConnectionPayload['provider_label'] ?? $adapter->label(),
            'provider_connection_label' => $connection->label,
            'source_reference' => $connection->label,
        ];

        try {
            $rows = $adapter->fetchPreview(
                credentials: $this->connectionService->runtimeCredentials($owner, $connection),
                queryContext: $queryContext,
                limit: $limit,
            );

            $normalizedRows = $adapter->normalizePreviewRows($rows, $queryContext);
            $normalizedRows = $this->importGuardService->annotatePreviewRows(
                owner: $owner,
                campaign: $campaign,
                sourceType: CampaignProspect::SOURCE_CONNECTOR,
                defaultSourceReference: $connection->label,
                rows: $normalizedRows,
            );
            $guardSummary = $this->importGuardService->previewSummary($normalizedRows);
        } catch (ConnectionException $exception) {
            ActivityLog::record(
                $actor,
                $campaign,
                'campaign_provider_preview_failed',
                [
                    'campaign_id' => $campaign->id,
                    'provider_connection_id' => $connection->id,
                    'provider_key' => $connection->provider_key,
                    'query_label' => $queryLabel !== '' ? $queryLabel : null,
                    'query' => $query,
                    'reason' => 'connection_exception',
                ],
                'Provider preview failed'
            );

            throw ValidationException::withMessages([
                'provider_connection_id' => 'The provider request timed out or could not be reached. Try again shortly.',
            ]);
        }

        ActivityLog::record(
            $actor,
            $campaign,
            'campaign_provider_preview_generated',
            [
                'campaign_id' => $campaign->id,
                'provider_connection_id' => $connection->id,
                'provider_key' => $connection->provider_key,
                'source_reference' => $connection->label,
                'query_label' => $queryLabel !== '' ? $queryLabel : null,
                'query' => $query,
                'preview_count' => count($normalizedRows),
                'fresh_count' => $guardSummary['fresh_count'] ?? count($normalizedRows),
                'already_imported_count' => $guardSummary['already_imported_count'] ?? 0,
            ],
            'Provider preview generated'
        );

        return [
            'provider_connection' => $providerConnectionPayload,
            'preview' => [
                'query' => $query,
                'query_label' => $queryLabel !== '' ? $queryLabel : null,
                'limit' => $limit,
                'count' => count($normalizedRows),
                'selected_count' => (int) ($guardSummary['fresh_count'] ?? count($normalizedRows)),
                'generated_at' => now()->toIso8601String(),
                'fresh_count' => (int) ($guardSummary['fresh_count'] ?? count($normalizedRows)),
                'already_imported_count' => (int) ($guardSummary['already_imported_count'] ?? 0),
                'latest_imported_at' => $guardSummary['latest_imported_at'] ?? null,
            ],
            'rows' => $normalizedRows,
        ];
    }
}
