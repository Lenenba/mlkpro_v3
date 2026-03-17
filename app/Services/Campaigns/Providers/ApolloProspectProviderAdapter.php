<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ApolloProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    private const API_BASE_URL = 'https://api.apollo.io';

    private const AUTH_HEALTH_ENDPOINT = self::API_BASE_URL.'/v1/auth/health';

    private const PEOPLE_SEARCH_ENDPOINT = self::API_BASE_URL.'/api/v1/mixed_people/api_search';

    private const PEOPLE_BULK_MATCH_ENDPOINT = self::API_BASE_URL.'/api/v1/people/bulk_match';

    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_APOLLO;
    }

    public function label(): string
    {
        return 'Apollo';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array
    {
        return [
            [
                'key' => 'api_key',
                'label' => 'Apollo API key',
                'type' => 'password',
                'required' => true,
                'placeholder' => 'Paste your Apollo master API key',
                'help' => 'Requires People Search and Enrichment access. Stored encrypted for this tenant only.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, status: string, message: string, errors?: array<string, string>}
     */
    public function validateCredentials(array $credentials): array
    {
        $baseValidation = parent::validateCredentials($credentials);
        if (! ($baseValidation['ok'] ?? false)) {
            return $baseValidation;
        }

        $response = $this->apolloRequest((string) $credentials['api_key'])
            ->get(self::AUTH_HEALTH_ENDPOINT);

        if ($response->successful()) {
            return [
                'ok' => true,
                'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
                'message' => 'Apollo connection validated.',
            ];
        }

        if ($response->status() === 429) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RATE_LIMITED,
                'message' => $this->responseMessage($response, 'Apollo rate limit reached while validating the API key.'),
                'errors' => [
                    'api_key' => 'Apollo rate limit reached while validating the API key.',
                ],
            ];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_INVALID,
                'message' => $this->responseMessage($response, 'Apollo rejected the API key.'),
                'errors' => [
                    'api_key' => 'Apollo rejected the API key.',
                ],
            ];
        }

        return [
            'ok' => false,
            'status' => CampaignProspectProviderConnection::STATUS_DISCONNECTED,
            'message' => $this->responseMessage($response, 'Apollo validation failed unexpectedly.'),
            'errors' => [
                'api_key' => 'Apollo validation failed unexpectedly.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $queryContext
     * @return array<int, array<string, mixed>>
     */
    public function fetchPreview(array $credentials, array $queryContext, int $limit = 25): array
    {
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));
        $query = trim((string) ($queryContext['query'] ?? ''));

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Apollo API key is required.',
            ]);
        }

        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => 'Apollo preview requires a query or ICP.',
            ]);
        }

        $searchResponse = $this->apolloRequest($apiKey)->post(self::PEOPLE_SEARCH_ENDPOINT, [
            'q_keywords' => $query,
            'page' => 1,
            'per_page' => max(1, min(25, $limit)),
        ]);

        $this->assertApolloPreviewResponse($searchResponse, 'Apollo search preview failed.');

        $searchRows = array_slice($this->extractResponseRows($searchResponse), 0, $limit);
        if ($searchRows === []) {
            return [];
        }

        $enrichmentByPersonId = $this->fetchBulkEnrichment($apiKey, $searchRows);

        return collect($searchRows)
            ->map(function (array $row) use ($enrichmentByPersonId): array {
                $personId = $this->apolloPersonId($row);
                $enriched = $personId !== '' ? ($enrichmentByPersonId[$personId] ?? []) : [];

                return array_replace_recursive($row, $enriched);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function normalizePreviewRows(array $rows, array $context = []): array
    {
        $query = trim((string) ($context['query'] ?? ''));
        $queryLabel = trim((string) ($context['query_label'] ?? ''));
        $sourceReference = trim((string) (
            $context['source_reference']
                ?? $context['provider_connection_label']
                ?? $this->label()
        ));

        $genericRows = collect($rows)
            ->map(function (array $row) use ($query, $queryLabel): array {
                $organization = $this->organizationPayload($row);
                $person = is_array($row['person'] ?? null) ? $row['person'] : [];
                $personId = $this->apolloPersonId($row);
                $organizationId = trim((string) ($organization['id'] ?? $row['organization_id'] ?? data_get($row, 'person.organization_id') ?? ''));
                $companySize = $this->normalizeCompanySize($organization['estimated_num_employees'] ?? $row['estimated_num_employees'] ?? null);
                $website = $this->firstNonEmptyString([
                    $organization['website_url'] ?? null,
                    $organization['website'] ?? null,
                    $row['website_url'] ?? null,
                    $row['website'] ?? null,
                    $person['website_url'] ?? null,
                ]);
                $email = $this->firstNonEmptyString([
                    $row['email'] ?? null,
                    $row['work_email'] ?? null,
                    $person['email'] ?? null,
                    $person['work_email'] ?? null,
                    data_get($row, 'employment_history.0.email'),
                ]);
                $phone = $this->firstNonEmptyString([
                    $row['phone'] ?? null,
                    $person['phone'] ?? null,
                    data_get($row, 'sanitized_phone'),
                    data_get($row, 'phone_numbers.0.sanitized_number'),
                    data_get($row, 'phone_numbers.0.raw_number'),
                    data_get($row, 'person.phone_numbers.0.sanitized_number'),
                    data_get($row, 'person.phone_numbers.0.raw_number'),
                    data_get($row, 'mobile_phone_number'),
                ]);
                $title = $this->firstNonEmptyString([
                    $row['title'] ?? null,
                    $row['headline'] ?? null,
                    $person['title'] ?? null,
                    $person['headline'] ?? null,
                ]);
                $contactName = $this->firstNonEmptyString([
                    $row['name'] ?? null,
                    $row['contact_name'] ?? null,
                    $person['name'] ?? null,
                    trim(sprintf('%s %s', (string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? ''))),
                    trim(sprintf('%s %s', (string) ($person['first_name'] ?? ''), (string) ($person['last_name'] ?? ''))),
                ]);

                return [
                    'external_ref' => $personId !== '' ? $personId : null,
                    'company_name' => $this->firstNonEmptyString([
                        $organization['name'] ?? null,
                        $row['organization_name'] ?? null,
                        $row['company_name'] ?? null,
                    ]),
                    'contact_name' => $contactName,
                    'first_name' => $this->firstNonEmptyString([$row['first_name'] ?? null, $person['first_name'] ?? null]),
                    'last_name' => $this->firstNonEmptyString([$row['last_name'] ?? null, $person['last_name'] ?? null]),
                    'email' => $email,
                    'phone' => $phone,
                    'website' => $website,
                    'city' => $this->firstNonEmptyString([
                        $organization['city'] ?? null,
                        $row['city'] ?? null,
                    ]),
                    'state' => $this->firstNonEmptyString([
                        $organization['state'] ?? null,
                        $organization['region'] ?? null,
                        $row['state'] ?? null,
                    ]),
                    'country' => $this->firstNonEmptyString([
                        $organization['country'] ?? null,
                        $row['country'] ?? null,
                    ]),
                    'industry' => $this->firstNonEmptyString([
                        $organization['industry'] ?? null,
                        $row['industry'] ?? null,
                    ]),
                    'company_size' => $companySize,
                    'tags' => array_values(array_filter([
                        $this->label(),
                        $title !== '' ? $title : null,
                        $queryLabel !== '' ? $queryLabel : null,
                    ])),
                    'metadata' => array_filter([
                        'apollo_person_id' => $personId !== '' ? $personId : null,
                        'apollo_organization_id' => $organizationId !== '' ? $organizationId : null,
                        'apollo_title' => $title !== '' ? $title : null,
                        'apollo_linkedin_url' => $this->firstNonEmptyString([
                            $row['linkedin_url'] ?? null,
                            $row['linkedin_profile_url'] ?? null,
                            $person['linkedin_url'] ?? null,
                        ]),
                        'apollo_search_query' => $query !== '' ? $query : null,
                        'apollo_search_query_label' => $queryLabel !== '' ? $queryLabel : null,
                        'apollo_search_result' => true,
                    ], fn ($value) => $value !== null && $value !== ''),
                ];
            })
            ->values()
            ->all();

        return parent::normalizePreviewRows($genericRows, [
            ...$context,
            'provider_key' => $this->key(),
            'provider_label' => $this->label(),
            'source_reference' => $sourceReference,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchBulkEnrichment(string $apiKey, array $searchRows): array
    {
        $people = collect($searchRows)
            ->map(fn (array $row) => $this->apolloPersonId($row))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values();

        if ($people->isEmpty()) {
            return [];
        }

        $enriched = [];

        foreach ($people->chunk(10) as $chunk) {
            $details = $chunk
                ->map(fn (string $personId) => ['id' => $personId])
                ->values()
                ->all();

            $response = $this->apolloRequest($apiKey)->post(self::PEOPLE_BULK_MATCH_ENDPOINT, [
                'details' => $details,
                'reveal_phone_number' => true,
                'reveal_personal_emails' => false,
            ]);

            $this->assertApolloPreviewResponse($response, 'Apollo enrichment failed while preparing the preview.');

            foreach ($this->extractResponseRows($response) as $row) {
                $personId = $this->apolloPersonId($row);
                if ($personId === '') {
                    continue;
                }

                $enriched[$personId] = $row;
            }
        }

        return $enriched;
    }

    private function apolloRequest(string $apiKey): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->withHeaders([
                'X-Api-Key' => $apiKey,
            ]);
    }

    private function assertApolloPreviewResponse(Response $response, string $fallbackMessage): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $this->responseMessage($response, $fallbackMessage);

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Apollo rate limit reached. Try again shortly.',
            ]);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw ValidationException::withMessages([
                'provider_connection_id' => $message !== '' ? $message : 'Apollo rejected the API key or permissions for this request.',
            ]);
        }

        throw ValidationException::withMessages([
            'provider_connection_id' => $message !== '' ? $message : $fallbackMessage,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractResponseRows(Response $response): array
    {
        $payload = $response->json();
        $candidates = [
            $payload['people'] ?? null,
            $payload['contacts'] ?? null,
            $payload['matches'] ?? null,
            $payload['results'] ?? null,
            data_get($payload, 'data.people'),
            data_get($payload, 'data.matches'),
            data_get($payload, 'data.results'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, fn ($row) => is_array($row)));
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationPayload(array $row): array
    {
        $organization = $row['organization']
            ?? $row['account']
            ?? data_get($row, 'person.organization')
            ?? null;

        return is_array($organization) ? $organization : [];
    }

    private function apolloPersonId(array $row): string
    {
        return trim((string) (
            $row['id']
                ?? data_get($row, 'person.id')
                ?? $row['person_id']
                ?? ''
        ));
    }

    private function normalizeCompanySize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $count = (int) $value;
            if ($count <= 10) {
                return '1-10';
            }
            if ($count <= 50) {
                return '11-50';
            }
            if ($count <= 200) {
                return '51-200';
            }
            if ($count <= 500) {
                return '201-500';
            }

            return '500+';
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function responseMessage(Response $response, string $fallback): string
    {
        return $this->firstNonEmptyString([
            data_get($response->json(), 'message'),
            data_get($response->json(), 'error'),
            data_get($response->json(), 'errors.0.message'),
            data_get($response->json(), 'errors.message'),
            $fallback,
        ]);
    }
}
