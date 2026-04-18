<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ApolloProspectProviderAdapter extends AbstractOauthProspectProviderAdapter
{
    private const AUTHORIZATION_ENDPOINT = 'https://app.apollo.io/#/oauth/authorize';

    private const TOKEN_ENDPOINT = 'https://app.apollo.io/api/v1/oauth/token';

    private const PROFILE_ENDPOINT = 'https://app.apollo.io/api/v1/users/api_profile';

    private const PEOPLE_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/mixed_people/api_search';

    private const PEOPLE_BULK_MATCH_ENDPOINT = 'https://api.apollo.io/api/v1/people/bulk_match';

    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_APOLLO;
    }

    public function label(): string
    {
        return 'Apollo';
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        $configuredScopes = config('services.apollo.oauth.scopes', []);

        if (! is_array($configuredScopes)) {
            return [];
        }

        return collect($configuredScopes)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn (string $value) => $value !== '')
            ->values()
            ->all();
    }

    public function setupRequired(): bool
    {
        return trim((string) config('services.apollo.oauth.client_id')) === ''
            || trim((string) config('services.apollo.oauth.client_secret')) === '';
    }

    public function setupMessage(): ?string
    {
        if (! $this->setupRequired()) {
            return null;
        }

        return 'Configure APOLLO_OAUTH_CLIENT_ID and APOLLO_OAUTH_CLIENT_SECRET to enable the Apollo redirect flow.';
    }

    public function beginAuthorization(CampaignProspectProviderConnection $connection, string $state): ?string
    {
        if ($this->setupRequired()) {
            throw ValidationException::withMessages([
                'provider_key' => $this->setupMessage() ?? 'Apollo OAuth is not configured yet.',
            ]);
        }

        $query = [
            'client_id' => (string) config('services.apollo.oauth.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
        ];

        $scopes = $this->scopes();
        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }

        return config('services.apollo.oauth.authorize_url', self::AUTHORIZATION_ENDPOINT).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function completeAuthorization(array $payload): array
    {
        $code = trim((string) ($payload['code'] ?? ''));

        if ($code === '') {
            throw ValidationException::withMessages([
                'provider' => 'Apollo did not return an authorization code.',
            ]);
        }

        $tokenPayload = $this->exchangeToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        return $this->buildOauthAuthorizationResult($tokenPayload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function refreshCredentials(array $credentials): array
    {
        $refreshToken = trim((string) ($credentials['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw ValidationException::withMessages([
                'provider' => 'Apollo must be reconnected because no refresh token is available.',
            ]);
        }

        $tokenPayload = $this->exchangeToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return [
            ...$this->buildOauthAuthorizationResult($tokenPayload),
            'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
            'message' => 'Apollo tokens refreshed.',
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, status: string, message: string, errors?: array<string, string>}
     */
    public function validateCredentials(array $credentials): array
    {
        $token = $this->runtimeToken($credentials);
        if ($token === '') {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'message' => 'Apollo must be reconnected before it can be used.',
            ];
        }

        $response = $this->profileRequest($token)->get(config('services.apollo.oauth.profile_url', self::PROFILE_ENDPOINT));

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
                'message' => $this->responseMessage($response, 'Apollo rate limit reached while validating this connection.'),
            ];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_RECONNECT_REQUIRED,
                'message' => $this->responseMessage($response, 'Apollo authorization has expired or no longer grants the required scopes.'),
            ];
        }

        return [
            'ok' => false,
            'status' => CampaignProspectProviderConnection::STATUS_ERROR,
            'message' => $this->responseMessage($response, 'Apollo validation failed unexpectedly.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $queryContext
     * @return array<int, array<string, mixed>>
     */
    public function fetchPreview(array $credentials, array $queryContext, int $limit = 25): array
    {
        $token = $this->runtimeToken($credentials);
        $query = trim((string) ($queryContext['query'] ?? ''));

        if ($token === '') {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Apollo must be connected before previewing prospects.',
            ]);
        }

        if ($query === '') {
            throw ValidationException::withMessages([
                'query' => 'Apollo preview requires a query or ICP.',
            ]);
        }

        $searchResponse = $this->apolloRequest($credentials)->post(self::PEOPLE_SEARCH_ENDPOINT, [
            'q_keywords' => $query,
            'page' => 1,
            'per_page' => max(1, min(25, $limit)),
        ]);

        $this->assertApolloPreviewResponse($searchResponse, 'Apollo search preview failed.');

        $searchRows = array_slice($this->extractResponseRows($searchResponse), 0, $limit);
        if ($searchRows === []) {
            return [];
        }

        $enrichmentByPersonId = $this->fetchBulkEnrichment($credentials, $searchRows);

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

    protected function shortDescription(): string
    {
        return 'Connect Apollo with a native OAuth redirect so the tenant can approve access without copying secrets.';
    }

    protected function connectDescription(): string
    {
        return 'Your team signs into Apollo, reviews the requested scopes, and returns with the workspace linked automatically.';
    }

    /**
     * @param  array<string, mixed>  $tokenPayload
     * @return array<string, mixed>
     */
    private function buildOauthAuthorizationResult(array $tokenPayload): array
    {
        $normalizedTokenPayload = $this->normalizeOauthTokenPayload($tokenPayload);
        $runtimeCredentials = (array) ($normalizedTokenPayload['credentials'] ?? []);
        $profileResponse = $this->profileRequest((string) ($runtimeCredentials['access_token'] ?? ''))
            ->get(config('services.apollo.oauth.profile_url', self::PROFILE_ENDPOINT));

        if (! $profileResponse->successful()) {
            throw ValidationException::withMessages([
                'provider' => $this->responseMessage($profileResponse, 'Apollo connected, but the user profile could not be loaded.'),
            ]);
        }

        $externalAccount = $this->normalizeExternalAccount((array) $profileResponse->json());

        return [
            ...$normalizedTokenPayload,
            'external_account_id' => $externalAccount['external_account_id'] ?? null,
            'external_account_label' => $externalAccount['external_account_label'] ?? null,
            'metadata' => [
                ...((array) ($normalizedTokenPayload['metadata'] ?? [])),
                ...((array) ($externalAccount['metadata'] ?? [])),
                'oauth_provider' => $this->key(),
                'oauth_connected' => true,
            ],
            'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
            'message' => 'Apollo connected.',
        ];
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function exchangeToken(array $payload): array
    {
        if ($this->setupRequired()) {
            throw ValidationException::withMessages([
                'provider' => $this->setupMessage() ?? 'Apollo OAuth is not configured yet.',
            ]);
        }

        $response = Http::acceptJson()
            ->asForm()
            ->timeout(20)
            ->post(config('services.apollo.oauth.token_url', self::TOKEN_ENDPOINT), [
                ...$payload,
                'client_id' => (string) config('services.apollo.oauth.client_id'),
                'client_secret' => (string) config('services.apollo.oauth.client_secret'),
                'redirect_uri' => $this->redirectUri(),
            ]);

        if ($response->successful()) {
            return (array) $response->json();
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw ValidationException::withMessages([
                'provider' => $this->responseMessage($response, 'Apollo rejected the authorization response. Reconnect the account and try again.'),
            ]);
        }

        throw ValidationException::withMessages([
            'provider' => $this->responseMessage($response, 'Apollo token exchange failed unexpectedly.'),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchBulkEnrichment(array $credentials, array $searchRows): array
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

            $response = $this->apolloRequest($credentials)->post(self::PEOPLE_BULK_MATCH_ENDPOINT, [
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

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function apolloRequest(array $credentials): PendingRequest
    {
        $token = $this->runtimeToken($credentials);

        return Http::acceptJson()
            ->timeout(20)
            ->withToken($token);
    }

    private function profileRequest(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->withToken($token);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function runtimeToken(array $credentials): string
    {
        return trim((string) ($credentials['access_token'] ?? $credentials['api_key'] ?? ''));
    }

    private function redirectUri(): string
    {
        $configured = trim((string) config('services.apollo.oauth.redirect_uri', ''));

        return $configured !== ''
            ? $configured
            : route('marketing.prospect-providers.oauth.callback', ['provider' => $this->key()]);
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
                'provider_connection_id' => $message !== '' ? $message : 'Apollo authorization has expired or no longer grants the required scopes.',
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
}
