<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class LushaProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    private const API_BASE_URL = 'https://api.lusha.com';

    private const VALIDATION_ENDPOINT = self::API_BASE_URL.'/prospecting/filters/contacts/departments';

    private const CONTACT_SEARCH_ENDPOINT = self::API_BASE_URL.'/prospecting/contact/search';

    private const CONTACT_ENRICH_ENDPOINT = self::API_BASE_URL.'/prospecting/contact/enrich';

    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_LUSHA;
    }

    public function label(): string
    {
        return 'Lusha';
    }

    protected function shortDescription(): string
    {
        return 'Connect Lusha for prospect search and enrichment when your team needs a tenant-owned data source inside campaigns.';
    }

    protected function connectDescription(): string
    {
        return 'Lusha currently documents API key authentication for this API, so the connection uses an encrypted tenant-owned key instead of a redirect flow.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array
    {
        return [
            [
                'key' => 'api_key',
                'label' => 'Lusha API key',
                'type' => 'password',
                'required' => true,
                'placeholder' => 'Paste your Lusha API key',
                'help' => 'Requires prospecting search and contact enrichment access. Stored encrypted for this tenant only.',
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

        $response = $this->lushaRequest((string) $credentials['api_key'])
            ->get(self::VALIDATION_ENDPOINT);

        if ($response->successful()) {
            return [
                'ok' => true,
                'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
                'message' => 'Lusha connection validated.',
            ];
        }

        return $this->validationFailure($response);
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
                'provider_connection_id' => 'Lusha API key is required.',
            ]);
        }

        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => 'Lusha preview requires a query or ICP.',
            ]);
        }

        $searchResponse = $this->lushaRequest($apiKey)->post(self::CONTACT_SEARCH_ENDPOINT, [
            'pages' => [
                'page' => 0,
                'size' => max(10, min(25, $limit)),
            ],
            'includePartialContact' => true,
            'filters' => [
                'contacts' => [
                    'include' => [
                        'existing_data_points' => [
                            'work_email',
                        ],
                        'searchText' => $query,
                    ],
                ],
            ],
        ]);

        $this->assertLushaPreviewResponse($searchResponse, 'Lusha search preview failed.');

        $payload = $searchResponse->json();
        $requestId = trim((string) ($payload['requestId'] ?? data_get($payload, 'data.requestId') ?? ''));
        $searchRows = array_slice($this->extractResponseRows($payload), 0, $limit);

        if ($searchRows === []) {
            return [];
        }

        $enrichmentByContactId = $this->fetchContactEnrichment($apiKey, $requestId, $searchRows);

        return collect($searchRows)
            ->map(function (array $row) use ($enrichmentByContactId): array {
                $contactId = $this->lushaContactId($row);
                $enriched = $contactId !== '' ? ($enrichmentByContactId[$contactId] ?? []) : [];

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
                $company = $this->companyPayload($row);
                $contactId = $this->lushaContactId($row);
                $companyId = trim((string) ($company['id'] ?? $row['companyId'] ?? $row['company_id'] ?? ''));
                $title = $this->firstNonEmptyString([
                    $row['jobTitle'] ?? null,
                    $row['title'] ?? null,
                    data_get($row, 'position'),
                ]);

                return [
                    'external_ref' => $contactId !== '' ? $contactId : null,
                    'company_name' => $this->firstNonEmptyString([
                        $company['name'] ?? null,
                        $row['companyName'] ?? null,
                        $row['company_name'] ?? null,
                    ]),
                    'contact_name' => $this->firstNonEmptyString([
                        $row['fullName'] ?? null,
                        $row['name'] ?? null,
                        trim(sprintf('%s %s', (string) ($row['firstName'] ?? ''), (string) ($row['lastName'] ?? ''))),
                    ]),
                    'first_name' => $this->firstNonEmptyString([
                        $row['firstName'] ?? null,
                        $row['first_name'] ?? null,
                    ]),
                    'last_name' => $this->firstNonEmptyString([
                        $row['lastName'] ?? null,
                        $row['last_name'] ?? null,
                    ]),
                    'email' => $this->firstEmail($row),
                    'phone' => $this->firstPhone($row),
                    'website' => $this->firstNonEmptyString([
                        $company['website'] ?? null,
                        $company['websiteUrl'] ?? null,
                        $company['fqdn'] ?? null,
                        $company['domain'] ?? null,
                        $row['companyWebsite'] ?? null,
                        $row['website'] ?? null,
                        $row['fqdn'] ?? null,
                    ]),
                    'city' => $this->firstNonEmptyString([
                        $company['city'] ?? null,
                        data_get($company, 'location.city'),
                        $row['city'] ?? null,
                    ]),
                    'state' => $this->firstNonEmptyString([
                        $company['state'] ?? null,
                        $company['region'] ?? null,
                        data_get($company, 'location.state'),
                        $row['state'] ?? null,
                    ]),
                    'country' => $this->firstNonEmptyString([
                        $company['country'] ?? null,
                        data_get($company, 'location.country'),
                        data_get($row, 'location.country'),
                        $row['country'] ?? null,
                    ]),
                    'industry' => $this->firstNonEmptyString([
                        $company['industry'] ?? null,
                        $company['mainIndustry'] ?? null,
                        $row['industry'] ?? null,
                    ]),
                    'company_size' => $this->normalizeCompanySize(
                        $company['employeeCount'] ?? $company['employeeCountRange'] ?? $company['employees'] ?? $row['employeeCount'] ?? null
                    ),
                    'tags' => array_values(array_filter([
                        $this->label(),
                        $title !== '' ? $title : null,
                        $queryLabel !== '' ? $queryLabel : null,
                    ])),
                    'metadata' => array_filter([
                        'lusha_contact_id' => $contactId !== '' ? $contactId : null,
                        'lusha_company_id' => $companyId !== '' ? $companyId : null,
                        'lusha_job_title' => $title !== '' ? $title : null,
                        'lusha_linkedin_url' => $this->firstNonEmptyString([
                            $row['linkedinUrl'] ?? null,
                            $row['linkedin_url'] ?? null,
                        ]),
                        'lusha_search_query' => $query !== '' ? $query : null,
                        'lusha_search_query_label' => $queryLabel !== '' ? $queryLabel : null,
                        'lusha_search_result' => true,
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
    private function fetchContactEnrichment(string $apiKey, string $requestId, array $searchRows): array
    {
        if ($requestId === '') {
            return [];
        }

        $contactIds = collect($searchRows)
            ->map(fn (array $row) => $this->lushaContactId($row))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        $response = $this->lushaRequest($apiKey)->post(self::CONTACT_ENRICH_ENDPOINT, [
            'requestId' => $requestId,
            'contactIds' => $contactIds->all(),
        ]);

        $this->assertLushaPreviewResponse($response, 'Lusha contact enrichment failed while preparing the preview.');

        return collect($this->extractResponseRows($response->json()))
            ->mapWithKeys(function (array $row): array {
                $contactId = $this->lushaContactId($row);
                if ($contactId === '') {
                    return [];
                }

                $flattened = is_array($row['data'] ?? null)
                    ? array_replace_recursive($row, $row['data'])
                    : $row;

                return [$contactId => array_replace_recursive(
                    $flattened,
                    [
                        'id' => $contactId,
                        'contactId' => $contactId,
                    ]
                )];
            })
            ->all();
    }

    private function lushaRequest(string $apiKey): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->withHeaders([
                'api_key' => $apiKey,
            ]);
    }

    /**
     * @return array{ok: bool, status: string, message: string, errors: array<string, string>}
     */
    private function validationFailure(Response $response): array
    {
        $message = $this->responseMessage($response, 'Lusha validation failed unexpectedly.');

        if ($response->status() === 429) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RATE_LIMITED,
                'message' => $message,
                'errors' => ['api_key' => 'Lusha rate limit reached while validating the API key.'],
            ];
        }

        if ($response->status() === 402) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_EXPIRED,
                'message' => $message,
                'errors' => ['api_key' => 'Lusha account access or credits are not available for this API key.'],
            ];
        }

        if ($response->status() === 401) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'message' => $message,
                'errors' => ['api_key' => 'Lusha rejected the API key.'],
            ];
        }

        return [
            'ok' => false,
            'status' => CampaignProspectProviderConnection::STATUS_ERROR,
            'message' => $message,
            'errors' => ['api_key' => 'Lusha validation failed unexpectedly.'],
        ];
    }

    private function assertLushaPreviewResponse(Response $response, string $fallbackMessage): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $this->responseMessage($response, $fallbackMessage);

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Lusha rate limit reached. Try again shortly.',
            ]);
        }

        if (in_array($response->status(), [401, 402, 403], true)) {
            throw ValidationException::withMessages([
                'provider_connection_id' => $message !== '' ? $message : 'Lusha rejected the API key or plan for this request.',
            ]);
        }

        throw ValidationException::withMessages([
            'provider_connection_id' => $message !== '' ? $message : $fallbackMessage,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractResponseRows(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $candidates = [
            $payload['contacts'] ?? null,
            $payload['results'] ?? null,
            $payload['items'] ?? null,
            $payload['data'] ?? null,
            data_get($payload, 'data.contacts'),
            data_get($payload, 'data.results'),
            data_get($payload, 'data.items'),
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
    private function companyPayload(array $row): array
    {
        $company = $row['company'] ?? $row['organization'] ?? null;

        return is_array($company) ? $company : [];
    }

    private function lushaContactId(array $row): string
    {
        return trim((string) ($row['id'] ?? $row['contactId'] ?? $row['contact_id'] ?? ''));
    }

    private function firstEmail(array $row): string
    {
        $candidates = [
            $row['email'] ?? null,
            $row['workEmail'] ?? null,
            data_get($row, 'emails.0.email'),
            data_get($row, 'emails.0.address'),
            data_get($row, 'emailAddresses.0.email'),
            data_get($row, 'emailAddresses.0.address'),
        ];

        return $this->firstNonEmptyString($candidates);
    }

    private function firstPhone(array $row): string
    {
        $candidates = [
            $row['phone'] ?? null,
            $row['workPhone'] ?? null,
            data_get($row, 'phones.0.number'),
            data_get($row, 'phones.0.internationalNumber'),
            data_get($row, 'phoneNumbers.0.number'),
            data_get($row, 'phoneNumbers.0.internationalNumber'),
        ];

        return $this->firstNonEmptyString($candidates);
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

        if ($normalized === '' || str_contains(strtolower($normalized), 'undefined')) {
            return null;
        }

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
            data_get($response->json(), 'details'),
            data_get($response->json(), 'errors.0.message'),
            data_get($response->json(), 'errors.message'),
            $fallback,
        ]);
    }
}
