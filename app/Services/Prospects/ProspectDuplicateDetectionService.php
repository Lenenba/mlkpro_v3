<?php

namespace App\Services\Prospects;

use App\Models\Request as LeadRequest;
use Illuminate\Support\Collection;

class ProspectDuplicateDetectionService
{
    private const MINIMUM_SCORE = 45;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forLead(LeadRequest $lead, int $limit = 10, bool $includeArchived = false): Collection
    {
        return $this->search(
            accountId: (int) $lead->user_id,
            attributes: $this->extractComparableAttributes($lead),
            ignoreId: (int) $lead->id,
            limit: $limit,
            includeArchived: $includeArchived,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, array<string, mixed>>
     */
    public function search(
        int $accountId,
        array $attributes,
        ?int $ignoreId = null,
        int $limit = 10,
        bool $includeArchived = false
    ): Collection {
        $source = $this->normalizeAttributes($attributes);
        if (collect($source)->filter()->isEmpty()) {
            return collect();
        }

        $candidates = LeadRequest::query()
            ->where('user_id', $accountId)
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->with(['assignee.user:id,name'])
            ->get([
                'id',
                'user_id',
                'assigned_team_member_id',
                'status',
                'title',
                'service_type',
                'contact_name',
                'contact_email',
                'contact_phone',
                'street1',
                'street2',
                'city',
                'state',
                'postal_code',
                'country',
                'created_at',
                'archived_at',
                'meta',
            ]);

        return $candidates
            ->map(fn (LeadRequest $candidate) => $this->scoreCandidate($source, $candidate))
            ->filter()
            ->sort(function (array $left, array $right): int {
                $scoreComparison = ($right['duplicate_score'] ?? 0) <=> ($left['duplicate_score'] ?? 0);
                if ($scoreComparison !== 0) {
                    return $scoreComparison;
                }

                return strtotime((string) ($right['created_at'] ?? '')) <=> strtotime((string) ($left['created_at'] ?? ''));
            })
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<string, string|null>  $source
     * @return array<string, mixed>|null
     */
    private function scoreCandidate(array $source, LeadRequest $candidate): ?array
    {
        $target = $this->extractComparableAttributes($candidate);
        $reasons = [];
        $score = 0;

        if ($source['email'] && $source['email'] === $target['email']) {
            $reasons[] = $this->reason('email_exact', 'Exact email match', 100);
            $score += 100;
        }

        if ($source['phone'] && $source['phone'] === $target['phone']) {
            $reasons[] = $this->reason('phone_exact', 'Exact phone match', 90);
            $score += 90;
        }

        if ($source['contact_name'] && $source['contact_name'] === $target['contact_name']) {
            $reasons[] = $this->reason('name_exact', 'Exact contact name match', 50);
            $score += 50;
        }

        if ($source['company_name'] && $source['company_name'] === $target['company_name']) {
            $reasons[] = $this->reason('company_exact', 'Exact company match', 35);
            $score += 35;
        }

        if ($source['street'] && $source['street'] === $target['street']) {
            $reasons[] = $this->reason('street_exact', 'Exact street address match', 25);
            $score += 25;
        }

        if ($source['postal_code'] && $source['postal_code'] === $target['postal_code']) {
            $reasons[] = $this->reason('postal_code_exact', 'Exact postal code match', 15);
            $score += 15;
        }

        if ($source['city'] && $source['city'] === $target['city']) {
            $reasons[] = $this->reason('city_exact', 'Exact city match', 10);
            $score += 10;
        }

        if (
            $source['contact_name']
            && $source['company_name']
            && $source['contact_name'] === $target['contact_name']
            && $source['company_name'] === $target['company_name']
        ) {
            $reasons[] = $this->reason('name_company_combo', 'Same contact and company', 15);
            $score += 15;
        }

        if (
            $source['street']
            && $source['street'] === $target['street']
            && (
                ($source['postal_code'] && $source['postal_code'] === $target['postal_code'])
                || ($source['city'] && $source['city'] === $target['city'])
            )
        ) {
            $reasons[] = $this->reason('address_compound', 'Same address area', 20);
            $score += 20;
        }

        $score = min(100, $score);

        if ($score < self::MINIMUM_SCORE) {
            return null;
        }

        return [
            'id' => $candidate->id,
            'status' => $candidate->status,
            'title' => $candidate->title,
            'service_type' => $candidate->service_type,
            'contact_name' => $candidate->contact_name,
            'contact_email' => $candidate->contact_email,
            'contact_phone' => $candidate->contact_phone,
            'created_at' => optional($candidate->created_at)->toIso8601String(),
            'archived_at' => optional($candidate->archived_at)->toIso8601String(),
            'assignee' => $candidate->assignee
                ? [
                    'id' => $candidate->assignee->id,
                    'name' => $candidate->assignee->user?->name ?? 'Team member',
                ]
                : null,
            'duplicate_score' => $score,
            'duplicate_reasons' => $reasons,
            'duplicate_reason_codes' => collect($reasons)->pluck('code')->values()->all(),
            'duplicate_summary' => collect($reasons)->pluck('label')->implode(' · '),
        ];
    }

    /**
     * @return array{email:?string,phone:?string,contact_name:?string,company_name:?string,street:?string,postal_code:?string,city:?string}
     */
    private function extractComparableAttributes(LeadRequest $lead): array
    {
        return $this->normalizeAttributes([
            'contact_email' => $lead->contact_email,
            'contact_phone' => $lead->contact_phone,
            'contact_name' => $lead->contact_name,
            'company_name' => data_get($lead->meta, 'company_name'),
            'street1' => $lead->street1,
            'street2' => $lead->street2,
            'postal_code' => $lead->postal_code,
            'city' => $lead->city,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{email:?string,phone:?string,contact_name:?string,company_name:?string,street:?string,postal_code:?string,city:?string}
     */
    private function normalizeAttributes(array $attributes): array
    {
        return [
            'email' => $this->normalizeEmail($attributes['contact_email'] ?? $attributes['email'] ?? null),
            'phone' => $this->normalizePhone($attributes['contact_phone'] ?? $attributes['phone'] ?? null),
            'contact_name' => $this->normalizeText($attributes['contact_name'] ?? $attributes['name'] ?? null),
            'company_name' => $this->normalizeText($attributes['company_name'] ?? data_get($attributes, 'meta.company_name')),
            'street' => $this->normalizeText(implode(' ', array_filter([
                $attributes['street1'] ?? null,
                $attributes['street2'] ?? null,
            ]))),
            'postal_code' => $this->normalizeText($attributes['postal_code'] ?? $attributes['zip'] ?? null),
            'city' => $this->normalizeText($attributes['city'] ?? null),
        ];
    }

    /**
     * @return array{code:string,label:string,weight:int}
     */
    private function reason(string $code, string $label, int $weight): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'weight' => $weight,
        ];
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits !== '' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    private function normalizeText(mixed $value): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';

        return $normalized !== '' ? $normalized : null;
    }
}
