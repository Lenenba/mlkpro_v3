<?php

namespace App\Services\Prospects;

use App\Models\Request as LeadRequest;
use Illuminate\Support\Collection;

class ProspectDuplicateAlertService
{
    public function __construct(
        private readonly ProspectDuplicateDetectionService $duplicateDetection,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function forAttributes(
        int $accountId,
        array $attributes,
        string $context = 'create',
        ?int $ignoreId = null
    ): ?array {
        $duplicates = $this->duplicateDetection->search(
            accountId: $accountId,
            attributes: $attributes,
            ignoreId: $ignoreId,
        );

        return $this->buildAlert($context, collect([
            $this->buildEntry(
                key: $context.'-draft',
                attributes: $attributes,
                duplicates: $duplicates,
            ),
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forLead(LeadRequest $lead, string $context = 'convert'): ?array
    {
        $duplicates = $this->duplicateDetection->forLead($lead);

        return $this->buildAlert($context, collect([
            $this->buildEntry(
                key: $context.'-lead-'.$lead->id,
                attributes: [
                    'title' => $lead->title,
                    'service_type' => $lead->service_type,
                    'contact_name' => $lead->contact_name,
                    'contact_email' => $lead->contact_email,
                    'contact_phone' => $lead->contact_phone,
                    'street1' => $lead->street1,
                    'street2' => $lead->street2,
                    'city' => $lead->city,
                    'postal_code' => $lead->postal_code,
                    'meta' => $lead->meta,
                ],
                duplicates: $duplicates,
            ),
        ]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<string, mixed>|null
     */
    public function forImportPayloads(int $accountId, array $payloads): ?array
    {
        $entries = collect($payloads)
            ->values()
            ->map(function (array $payload, int $index) use ($accountId) {
                $duplicates = $this->duplicateDetection->search(
                    accountId: $accountId,
                    attributes: $payload,
                );

                return $this->buildEntry(
                    key: 'import-row-'.($index + 2),
                    attributes: $payload,
                    duplicates: $duplicates,
                    rowNumber: $index + 2,
                );
            })
            ->filter()
            ->values();

        return $this->buildAlert('import', $entries);
    }

    /**
     * @param  Collection<int, array<string, mixed>|null>  $entries
     * @return array<string, mixed>|null
     */
    private function buildAlert(string $context, Collection $entries): ?array
    {
        $entries = $entries
            ->filter()
            ->values();

        if ($entries->isEmpty()) {
            return null;
        }

        return [
            'context' => $context,
            'entry_count' => $entries->count(),
            'match_count' => (int) $entries->sum('match_count'),
            'strongest_score' => (int) ($entries->max('strongest_score') ?? 0),
            'entries' => $entries->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function buildEntry(
        string $key,
        array $attributes,
        Collection $duplicates,
        ?int $rowNumber = null
    ): ?array {
        if ($duplicates->isEmpty()) {
            return null;
        }

        return [
            'key' => $key,
            'row_number' => $rowNumber,
            'label' => $this->entryLabel($attributes),
            'subtitle' => $this->entrySubtitle($attributes),
            'match_count' => $duplicates->count(),
            'strongest_score' => (int) ($duplicates->max('duplicate_score') ?? 0),
            'duplicates' => $duplicates->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entryLabel(array $attributes): string
    {
        $label = trim((string) (
            $attributes['title']
            ?? $attributes['service_type']
            ?? $attributes['contact_name']
            ?? $attributes['contact_email']
            ?? $attributes['contact_phone']
            ?? ''
        ));

        return $label !== '' ? $label : 'Prospect draft';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entrySubtitle(array $attributes): ?string
    {
        $parts = array_filter([
            $attributes['contact_name'] ?? null,
            $attributes['contact_email'] ?? null,
            $attributes['contact_phone'] ?? null,
            data_get($attributes, 'meta.company_name'),
            $attributes['city'] ?? null,
        ], static fn ($value) => filled($value));

        if ($parts === []) {
            return null;
        }

        return implode(' · ', array_map(
            static fn ($value) => trim((string) $value),
            $parts,
        ));
    }
}
