<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class UpLeadProspectProviderAdapter extends AbstractApiKeyProspectProviderAdapter
{
    private const API_BASE_URL = 'https://api.uplead.com/v2';

    private const CREDITS_ENDPOINT = self::API_BASE_URL.'/credits';

    private const QUICK_SEARCH_ENDPOINT = self::API_BASE_URL.'/quick-search';

    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_UPLEAD;
    }

    public function label(): string
    {
        return 'UpLead';
    }

    protected function shortDescription(): string
    {
        return 'Connect UpLead when your team needs another tenant-owned prospecting source alongside Apollo and Lusha.';
    }

    protected function connectDescription(): string
    {
        return 'UpLead uses an encrypted tenant-owned API key connection for this provider workflow.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array
    {
        return [
            [
                'key' => 'api_key',
                'label' => 'UpLead API key',
                'type' => 'password',
                'required' => true,
                'placeholder' => 'Paste your UpLead API key',
                'help' => 'Requires API access for credits and Quick Search. Stored encrypted for this tenant only.',
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

        $response = $this->upLeadRequest((string) $credentials['api_key'])
            ->get(self::CREDITS_ENDPOINT);

        if ($response->successful()) {
            return [
                'ok' => true,
                'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
                'message' => 'UpLead connection validated.',
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
                'provider_connection_id' => 'UpLead API key is required.',
            ]);
        }

        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => 'UpLead preview requires a query or ICP.',
            ]);
        }

        $response = $this->upLeadRequest($apiKey)->post(self::QUICK_SEARCH_ENDPOINT, [
            'type' => 'contact',
            'text' => $query,
        ]);

        $this->assertUpLeadPreviewResponse($response, 'UpLead Quick Search preview failed.');

        return array_slice($this->extractResponseRows($response->json()), 0, max(1, min(25, $limit)));
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
                $contactId = $this->upLeadContactId($row);
                $companyId = trim((string) ($company['id'] ?? $row['company_id'] ?? $row['organization_id'] ?? ''));
                $title = $this->firstNonEmptyString([
                    $row['title'] ?? null,
                    $row['job_title'] ?? null,
                ]);
                $domain = $this->firstNonEmptyString([
                    $company['domain'] ?? null,
                    $row['domain'] ?? null,
                    $row['website'] ?? null,
                ]);

                return [
                    'external_ref' => $contactId !== '' ? $contactId : null,
                    'company_name' => $this->firstNonEmptyString([
                        $company['company_name'] ?? null,
                        $company['name'] ?? null,
                        $row['company_name'] ?? null,
                        $row['organization_name'] ?? null,
                    ]),
                    'contact_name' => $this->firstNonEmptyString([
                        $row['name'] ?? null,
                        trim(sprintf('%s %s', (string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? ''))),
                    ]),
                    'first_name' => $this->firstNonEmptyString([$row['first_name'] ?? null]),
                    'last_name' => $this->firstNonEmptyString([$row['last_name'] ?? null]),
                    'email' => $this->firstEmail($row),
                    'phone' => $this->firstPhone($row),
                    'website' => $domain,
                    'city' => $this->firstNonEmptyString([
                        $company['city'] ?? null,
                        $row['city'] ?? null,
                    ]),
                    'state' => $this->firstNonEmptyString([
                        $company['state'] ?? null,
                        $row['state'] ?? null,
                    ]),
                    'country' => $this->firstNonEmptyString([
                        $company['country'] ?? null,
                        $row['country'] ?? null,
                    ]),
                    'industry' => $this->firstNonEmptyString([
                        $company['industry'] ?? null,
                        $row['industry'] ?? null,
                    ]),
                    'company_size' => $this->normalizeCompanySize(
                        $company['employees'] ?? $row['employees'] ?? null
                    ),
                    'tags' => array_values(array_filter([
                        $this->label(),
                        $title !== '' ? $title : null,
                        $queryLabel !== '' ? $queryLabel : null,
                    ])),
                    'metadata' => array_filter([
                        'uplead_contact_id' => $contactId !== '' ? $contactId : null,
                        'uplead_company_id' => $companyId !== '' ? $companyId : null,
                        'uplead_job_title' => $title !== '' ? $title : null,
                        'uplead_linkedin_url' => $this->firstNonEmptyString([
                            $row['linkedin_url'] ?? null,
                            $company['linkedin_url'] ?? null,
                        ]),
                        'uplead_email_status' => $this->firstNonEmptyString([
                            $row['email_status'] ?? null,
                            $row['work_email_status'] ?? null,
                        ]),
                        'uplead_search_query' => $query !== '' ? $query : null,
                        'uplead_search_query_label' => $queryLabel !== '' ? $queryLabel : null,
                        'uplead_search_result' => true,
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

    private function upLeadRequest(string $apiKey): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->withHeaders([
                'Authorization' => $apiKey,
            ]);
    }

    /**
     * @return array{ok: bool, status: string, message: string, errors: array<string, string>}
     */
    private function validationFailure(Response $response): array
    {
        $message = $this->responseMessage($response, 'UpLead validation failed unexpectedly.');

        if ($response->status() === 429) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RATE_LIMITED,
                'message' => $message,
                'errors' => ['api_key' => 'UpLead rate limit reached while validating the API key.'],
            ];
        }

        if ($response->status() === 403) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_EXPIRED,
                'message' => $message,
                'errors' => ['api_key' => 'UpLead account access is paused or the subscription does not allow this request.'],
            ];
        }

        if ($response->status() === 401) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'message' => $message,
                'errors' => ['api_key' => 'UpLead rejected the API key.'],
            ];
        }

        return [
            'ok' => false,
            'status' => CampaignProspectProviderConnection::STATUS_ERROR,
            'message' => $message,
            'errors' => ['api_key' => 'UpLead validation failed unexpectedly.'],
        ];
    }

    private function assertUpLeadPreviewResponse(Response $response, string $fallbackMessage): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $this->responseMessage($response, $fallbackMessage);

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'UpLead rate limit reached. Try again shortly.',
            ]);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw ValidationException::withMessages([
                'provider_connection_id' => $message !== '' ? $message : 'UpLead rejected the API key or plan for this request.',
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
            data_get($payload, 'data.results'),
            $payload['results'] ?? null,
            data_get($payload, 'data.contacts'),
            $payload['contacts'] ?? null,
            data_get($payload, 'data.items'),
            $payload['items'] ?? null,
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

    private function upLeadContactId(array $row): string
    {
        return trim((string) ($row['id'] ?? $row['contact_id'] ?? ''));
    }

    private function firstEmail(array $row): string
    {
        return $this->firstNonEmptyString([
            $row['email'] ?? null,
            $row['work_email'] ?? null,
            data_get($row, 'emails.0.email'),
            data_get($row, 'emails.0.address'),
        ]);
    }

    private function firstPhone(array $row): string
    {
        return $this->firstNonEmptyString([
            $row['phone_number'] ?? null,
            $row['phone'] ?? null,
            $row['mobile_directdial'] ?? null,
            data_get($row, 'phones.0.number'),
            data_get($row, 'phones.0.internationalNumber'),
        ]);
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
            data_get($response->json(), 'error.message'),
            data_get($response->json(), 'message'),
            data_get($response->json(), 'error'),
            data_get($response->json(), 'errors.0.message'),
            data_get($response->json(), 'errors.message'),
            $fallback,
        ]);
    }
}
