<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProspectProviderImportGuardService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function annotatePreviewRows(
        User $owner,
        Campaign $campaign,
        string $sourceType,
        ?string $defaultSourceReference,
        array $rows
    ): array {
        if ($rows === [] || $sourceType !== CampaignProspect::SOURCE_CONNECTOR) {
            return $rows;
        }

        $existingMap = $this->existingImportMap($owner, $campaign, $sourceType, $defaultSourceReference, $rows);

        return collect($rows)
            ->map(function (array $row) use ($defaultSourceReference, $existingMap): array {
                $sourceReference = $this->normalizeSourceReference($row, $defaultSourceReference);
                $externalRef = trim((string) ($row['external_ref'] ?? ''));
                $match = ($sourceReference !== '' && $externalRef !== '')
                    ? ($existingMap[$sourceReference.'|'.$externalRef] ?? null)
                    : null;

                return [
                    ...$row,
                    'already_imported' => $match !== null,
                    'already_imported_prospect_id' => $match['prospect_id'] ?? null,
                    'already_imported_batch_id' => $match['batch_id'] ?? null,
                    'already_imported_status' => $match['status'] ?? null,
                    'already_imported_at' => $match['created_at'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function previewSummary(array $rows): array
    {
        $alreadyImported = collect($rows)->filter(fn (array $row) => (bool) ($row['already_imported'] ?? false));
        $fresh = collect($rows)->reject(fn (array $row) => (bool) ($row['already_imported'] ?? false));

        return [
            'fresh_count' => $fresh->count(),
            'already_imported_count' => $alreadyImported->count(),
            'latest_imported_at' => $alreadyImported
                ->pluck('already_imported_at')
                ->filter()
                ->max(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function assertImportableSelection(
        User $owner,
        Campaign $campaign,
        string $sourceType,
        ?string $defaultSourceReference,
        array $rows
    ): void {
        if ($rows === [] || $sourceType !== CampaignProspect::SOURCE_CONNECTOR) {
            return;
        }

        $this->assertSelectionDoesNotRepeatWithinPayload($rows, $defaultSourceReference);

        $existingMap = $this->existingImportMap($owner, $campaign, $sourceType, $defaultSourceReference, $rows);
        if ($existingMap === []) {
            return;
        }

        $duplicates = collect($rows)
            ->map(function (array $row) use ($defaultSourceReference, $existingMap): ?string {
                $sourceReference = $this->normalizeSourceReference($row, $defaultSourceReference);
                $externalRef = trim((string) ($row['external_ref'] ?? ''));
                if ($sourceReference === '' || $externalRef === '') {
                    return null;
                }

                return array_key_exists($sourceReference.'|'.$externalRef, $existingMap)
                    ? $externalRef
                    : null;
            })
            ->filter()
            ->values();

        if ($duplicates->isEmpty()) {
            return;
        }

        $examples = $duplicates->take(3)->implode(', ');
        $message = sprintf(
            '%d selected provider row(s) were already imported for this campaign. Reload the preview and keep only fresh rows.',
            $duplicates->count()
        );

        if ($examples !== '') {
            $message .= sprintf(' Examples: %s.', $examples);
        }

        throw ValidationException::withMessages([
            'prospects' => [$message],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function existingImportMap(
        User $owner,
        Campaign $campaign,
        string $sourceType,
        ?string $defaultSourceReference,
        array $rows
    ): array {
        if ($sourceType !== CampaignProspect::SOURCE_CONNECTOR) {
            return [];
        }

        $referencesBySource = collect($rows)
            ->map(function (array $row) use ($defaultSourceReference): array {
                return [
                    'source_reference' => $this->normalizeSourceReference($row, $defaultSourceReference),
                    'external_ref' => trim((string) ($row['external_ref'] ?? '')),
                ];
            })
            ->filter(fn (array $item) => $item['source_reference'] !== '' && $item['external_ref'] !== '')
            ->groupBy('source_reference')
            ->map(fn (Collection $group) => $group->pluck('external_ref')->unique()->values()->all());

        if ($referencesBySource->isEmpty()) {
            return [];
        }

        $existing = [];

        foreach ($referencesBySource as $sourceReference => $externalRefs) {
            $matches = CampaignProspect::query()
                ->where('user_id', $owner->id)
                ->where('campaign_id', $campaign->id)
                ->where('source_type', $sourceType)
                ->where('source_reference', $sourceReference)
                ->whereIn('external_ref', $externalRefs)
                ->get([
                    'id',
                    'campaign_prospect_batch_id',
                    'source_reference',
                    'external_ref',
                    'status',
                    'created_at',
                ]);

            foreach ($matches as $match) {
                $key = $match->source_reference.'|'.$match->external_ref;
                $existing[$key] = [
                    'prospect_id' => $match->id,
                    'batch_id' => $match->campaign_prospect_batch_id,
                    'status' => $match->status,
                    'created_at' => optional($match->created_at)->toIso8601String(),
                ];
            }
        }

        return $existing;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertSelectionDoesNotRepeatWithinPayload(array $rows, ?string $defaultSourceReference): void
    {
        $duplicates = collect($rows)
            ->map(function (array $row) use ($defaultSourceReference): ?string {
                $sourceReference = $this->normalizeSourceReference($row, $defaultSourceReference);
                $externalRef = trim((string) ($row['external_ref'] ?? ''));

                if ($sourceReference === '' || $externalRef === '') {
                    return null;
                }

                return $sourceReference.'|'.$externalRef;
            })
            ->filter()
            ->countBy()
            ->filter(fn (int $count) => $count > 1)
            ->keys()
            ->values();

        if ($duplicates->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'prospects' => ['The selected provider rows contain repeated external references. Remove duplicate selections before importing.'],
        ]);
    }

    private function normalizeSourceReference(array $row, ?string $defaultSourceReference): string
    {
        return trim((string) ($row['source_reference'] ?? $defaultSourceReference ?? ''));
    }
}
