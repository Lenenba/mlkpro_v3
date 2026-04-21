<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApolloProspectProviderAdapter extends AbstractOauthProspectProviderAdapter
{
    private const AUTHORIZATION_ENDPOINT = 'https://app.apollo.io/#/oauth/authorize';

    private const TOKEN_ENDPOINT = 'https://app.apollo.io/api/v1/oauth/token';

    private const PROFILE_ENDPOINT = 'https://app.apollo.io/api/v1/users/api_profile';

    private const PEOPLE_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/mixed_people/api_search';

    private const CONTACTS_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/contacts/search';

    private const ACCOUNTS_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/accounts/search';

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
        $normalizedLimit = max(1, min(25, $limit));
        $fallbackSearchLimit = min(25, max($normalizedLimit * 2, 10));
        $contactSearchQueries = $this->contactSearchQueries($query);

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

        $searchRows = [];
        $searchFailures = [];
        $hasSuccessfulPersonSearch = false;

        $peopleSearch = $this->performApolloSearch(
            credentials: $credentials,
            endpoint: self::PEOPLE_SEARCH_ENDPOINT,
            source: 'people_search',
            payload: [
                'q_keywords' => $query,
                'page' => 1,
                'per_page' => $normalizedLimit,
            ],
            fallbackMessage: 'Apollo people search preview failed.',
        );

        $hasSuccessfulPersonSearch = $hasSuccessfulPersonSearch || $peopleSearch['successful'];
        $searchRows = $this->mergeApolloPreviewRows($searchRows, $peopleSearch['rows']);
        if (! $peopleSearch['successful']) {
            $searchFailures[] = $peopleSearch;
        }

        if (count($searchRows) < $normalizedLimit) {
            foreach ($contactSearchQueries as $contactSearchQuery) {
                if (count($searchRows) >= $normalizedLimit) {
                    break;
                }

                $contactsSearch = $this->performApolloSearch(
                    credentials: $credentials,
                    endpoint: self::CONTACTS_SEARCH_ENDPOINT,
                    source: 'contacts_search',
                    payload: [
                        'q_keywords' => $contactSearchQuery,
                        'page' => 1,
                        'per_page' => $fallbackSearchLimit,
                    ],
                    fallbackMessage: 'Apollo contacts search preview failed.',
                );

                $hasSuccessfulPersonSearch = $hasSuccessfulPersonSearch || $contactsSearch['successful'];
                $searchRows = $this->mergeApolloPreviewRows($searchRows, $contactsSearch['rows']);
                if (! $contactsSearch['successful']) {
                    $searchFailures[] = $contactsSearch;
                }
            }
        }

        if (count($searchRows) < $normalizedLimit) {
            $accountRows = [];

            foreach ($this->organizationSearchTerms($query) as $accountQuery) {
                $accountSearch = $this->performApolloSearch(
                    credentials: $credentials,
                    endpoint: self::ACCOUNTS_SEARCH_ENDPOINT,
                    source: 'accounts_search',
                    payload: [
                        'q_organization_name' => $accountQuery,
                        'page' => 1,
                        'per_page' => min(10, max($normalizedLimit, 5)),
                    ],
                    fallbackMessage: 'Apollo account search fallback failed.',
                );

                if ($accountSearch['successful']) {
                    $accountRows = $this->mergeApolloPreviewRows($accountRows, $accountSearch['rows']);
                } else {
                    $searchFailures[] = $accountSearch;
                }
            }

            foreach ($this->contactQueriesFromAccounts(array_values($accountRows)) as $accountContactQuery) {
                if (count($searchRows) >= $normalizedLimit) {
                    break;
                }

                $accountContactsSearch = $this->performApolloSearch(
                    credentials: $credentials,
                    endpoint: self::CONTACTS_SEARCH_ENDPOINT,
                    source: 'account_contacts_search',
                    payload: [
                        'q_keywords' => $accountContactQuery,
                        'page' => 1,
                        'per_page' => $fallbackSearchLimit,
                    ],
                    fallbackMessage: 'Apollo account contact fallback failed.',
                );

                $hasSuccessfulPersonSearch = $hasSuccessfulPersonSearch || $accountContactsSearch['successful'];
                $searchRows = $this->mergeApolloPreviewRows($searchRows, $accountContactsSearch['rows']);
                if (! $accountContactsSearch['successful']) {
                    $searchFailures[] = $accountContactsSearch;
                }
            }
        }

        $searchRows = $this->rankApolloPreviewRows(array_values($searchRows), $query);
        $searchRows = array_slice($searchRows, 0, $normalizedLimit);

        if ($searchRows === []) {
            if ($hasSuccessfulPersonSearch) {
                return [];
            }

            $this->throwApolloSearchFailures($searchFailures);
        }

        try {
            $enrichmentByPersonId = $this->fetchBulkEnrichment($credentials, $searchRows);
        } catch (ConnectionException) {
            $enrichmentByPersonId = [];
        }

        return collect($searchRows)
            ->map(function (array $row) use ($enrichmentByPersonId): array {
                $personId = $this->apolloEnrichmentPersonId($row);
                $enriched = $personId !== '' ? ($enrichmentByPersonId[$personId] ?? []) : [];

                return $enriched === []
                    ? $row
                    : $this->mergeApolloRow($row, $enriched);
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
                $personId = $this->apolloEnrichmentPersonId($row);
                $recordId = $this->apolloPreviewEntityId($row);
                $contactId = $this->apolloContactId($row);
                $organizationId = trim((string) ($organization['id'] ?? $row['organization_id'] ?? data_get($row, 'person.organization_id') ?? ''));
                $companySize = $this->normalizeCompanySize($organization['estimated_num_employees'] ?? $row['estimated_num_employees'] ?? null);
                $searchSources = $this->apolloSearchSources($row);
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
                    'external_ref' => $recordId !== '' ? $recordId : null,
                    'company_name' => $this->firstNonEmptyString([
                        $organization['name'] ?? null,
                        $row['account_name'] ?? null,
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
                        'apollo_contact_id' => $contactId !== '' ? $contactId : null,
                        'apollo_organization_id' => $organizationId !== '' ? $organizationId : null,
                        'apollo_title' => $title !== '' ? $title : null,
                        'apollo_linkedin_url' => $this->firstNonEmptyString([
                            $row['linkedin_url'] ?? null,
                            $row['linkedin_profile_url'] ?? null,
                            $person['linkedin_url'] ?? null,
                        ]),
                        'apollo_search_source' => $searchSources[0] ?? null,
                        'apollo_search_sources' => $searchSources !== [] ? $searchSources : null,
                        'apollo_search_query' => $query !== '' ? $query : null,
                        'apollo_search_query_label' => $queryLabel !== '' ? $queryLabel : null,
                        'apollo_search_result' => true,
                    ], fn ($value) => $value !== null && $value !== '' && $value !== []),
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
            ->map(fn (array $row) => $this->apolloEnrichmentPersonId($row))
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

            if (! $response->successful()) {
                break;
            }

            foreach ($this->extractResponseRows($response) as $row) {
                $personId = $this->apolloEnrichmentPersonId($this->annotateApolloSearchRow($row, 'bulk_match'));
                if ($personId === '') {
                    continue;
                }

                $enriched[$personId] = $this->annotateApolloSearchRow($row, 'bulk_match');
            }
        }

        return $enriched;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $payload
     * @return array{successful: bool, rows: array<int, array<string, mixed>>, status: int, message: string, source: string}
     */
    private function performApolloSearch(
        array $credentials,
        string $endpoint,
        string $source,
        array $payload,
        string $fallbackMessage,
    ): array {
        $response = $this->apolloRequest($credentials)->post($endpoint, $payload);

        return [
            'successful' => $response->successful(),
            'rows' => $response->successful()
                ? $this->annotateApolloSearchRows($this->extractResponseRows($response), $source, (string) ($payload['q_keywords'] ?? $payload['q_organization_name'] ?? ''))
                : [],
            'status' => $response->status(),
            'message' => $this->responseMessage($response, $fallbackMessage),
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<string, array<string, mixed>>
     */
    private function mergeApolloPreviewRows(array $existing, array $incoming): array
    {
        foreach ($incoming as $row) {
            $dedupKey = $this->apolloRowDedupKey($row);

            if (! array_key_exists($dedupKey, $existing)) {
                $existing[$dedupKey] = $row;

                continue;
            }

            $existing[$dedupKey] = $this->mergeApolloRow($existing[$dedupKey], $row);
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeApolloRow(array $existing, array $incoming): array
    {
        $existingScore = $this->apolloRowQualityScore($existing);
        $incomingScore = $this->apolloRowQualityScore($incoming);
        $preferred = $incomingScore >= $existingScore ? $incoming : $existing;
        $fallback = $incomingScore >= $existingScore ? $existing : $incoming;
        $merged = array_replace_recursive($fallback, $preferred);

        $merged['__apollo_search_sources'] = array_values(array_unique(array_merge(
            $this->apolloSearchSources($existing),
            $this->apolloSearchSources($incoming),
        )));

        $merged['__apollo_search_queries'] = array_values(array_unique(array_merge(
            $this->apolloSearchQueries($existing),
            $this->apolloSearchQueries($incoming),
        )));

        foreach (['__apollo_entity_person_id', '__apollo_entity_contact_id', '__apollo_entity_account_id'] as $internalKey) {
            $merged[$internalKey] = trim((string) ($preferred[$internalKey] ?? $fallback[$internalKey] ?? '')) ?: null;
            if ($merged[$internalKey] === null) {
                unset($merged[$internalKey]);
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function annotateApolloSearchRows(array $rows, string $source, string $query = ''): array
    {
        return collect($rows)
            ->map(fn (array $row): array => $this->annotateApolloSearchRow($row, $source, $query))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function annotateApolloSearchRow(array $row, string $source, string $query = ''): array
    {
        $row['__apollo_search_sources'] = array_values(array_unique(array_filter([
            ...$this->apolloSearchSources($row),
            $source,
        ])));

        $row['__apollo_search_queries'] = array_values(array_unique(array_filter([
            ...$this->apolloSearchQueries($row),
            $query !== '' ? $query : null,
        ])));

        if ($source === 'people_search') {
            $personId = trim((string) ($row['id'] ?? data_get($row, 'person.id') ?? $row['person_id'] ?? ''));
            if ($personId !== '') {
                $row['__apollo_entity_person_id'] = $personId;
            }
        }

        if (str_contains($source, 'contacts_search') || $source === 'account_contacts_search') {
            $contactId = trim((string) ($row['id'] ?? ''));
            $personId = trim((string) (data_get($row, 'person.id') ?? $row['person_id'] ?? ''));

            if ($contactId !== '') {
                $row['__apollo_entity_contact_id'] = $contactId;
            }

            if ($personId !== '') {
                $row['__apollo_entity_person_id'] = $personId;
            }
        }

        if ($source === 'accounts_search') {
            $accountId = trim((string) ($row['id'] ?? $row['account_id'] ?? ''));
            if ($accountId !== '') {
                $row['__apollo_entity_account_id'] = $accountId;
            }
        }

        if ($source === 'bulk_match') {
            $personId = trim((string) ($row['id'] ?? data_get($row, 'person.id') ?? $row['person_id'] ?? ''));
            if ($personId !== '') {
                $row['__apollo_entity_person_id'] = $personId;
            }
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function contactQueriesFromAccounts(array $rows): array
    {
        return collect($rows)
            ->flatMap(function (array $row): array {
                $organization = $this->organizationPayload($row);
                $website = $this->firstNonEmptyString([
                    $organization['website_url'] ?? null,
                    $organization['website'] ?? null,
                    $row['website_url'] ?? null,
                    $row['website'] ?? null,
                ]);
                $domain = trim((string) parse_url($website, PHP_URL_HOST));

                return array_values(array_filter([
                    trim((string) ($organization['name'] ?? $row['name'] ?? $row['account_name'] ?? '')),
                    $domain !== '' ? preg_replace('/^www\./i', '', $domain) : null,
                ]));
            })
            ->map(fn (string $value): string => preg_replace('/\s+/', ' ', trim($value)) ?: '')
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function contactSearchQueries(string $query): array
    {
        $normalizedQuery = preg_replace('/\s+/', ' ', trim(strip_tags($query))) ?: '';
        if ($normalizedQuery === '') {
            return [];
        }

        $locationPhrase = $this->extractLocationPhrase($normalizedQuery);
        $subjectPhrase = $this->extractBusinessSubject($normalizedQuery);
        $hasDecisionMakerTitle = $this->queryHasDecisionMakerTitle($normalizedQuery);
        $keywords = $this->searchKeywords($normalizedQuery);

        $queries = [$normalizedQuery];

        if ($locationPhrase !== '') {
            $queries[] = $locationPhrase;
        }

        if ($subjectPhrase !== '') {
            $queries[] = $subjectPhrase;
            $queries[] = $subjectPhrase.' services';
        }

        if ($locationPhrase !== '' && $subjectPhrase !== '') {
            $queries[] = $subjectPhrase.' '.$locationPhrase;
        }

        if ($this->queryTargetsSystemChangeNeed($normalizedQuery)) {
            $queries = [
                ...$queries,
                ...$this->systemChangeRoleQueries($locationPhrase, $subjectPhrase),
            ];
        }

        if ($locationPhrase !== '' && ! $hasDecisionMakerTitle) {
            foreach (['owner', 'founder', 'president', 'director', 'manager'] as $titleKeyword) {
                $queries[] = $locationPhrase.' '.$titleKeyword;
            }
        }

        foreach (array_slice($keywords, 0, 4) as $keyword) {
            $queries[] = $keyword;
        }

        return collect($queries)
            ->map(fn (string $value): string => preg_replace('/\s+/', ' ', trim($value)) ?: '')
            ->filter(fn (string $value): bool => $value !== '' && mb_strlen($value) >= 3)
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function organizationSearchTerms(string $query): array
    {
        $normalizedQuery = preg_replace('/\s+/', ' ', trim(strip_tags($query))) ?: '';
        $candidates = [];

        $locationPhrase = $this->extractLocationPhrase($normalizedQuery);
        $subjectPhrase = $this->extractBusinessSubject($normalizedQuery);
        $keywords = $this->searchKeywords($normalizedQuery);

        if ($subjectPhrase !== '') {
            $candidates[] = $subjectPhrase;
        }

        if ($locationPhrase !== '') {
            $candidates[] = $locationPhrase;
        }

        if (preg_match_all('/\b(?:https?:\/\/)?(?:www\.)?([a-z0-9-]+\.[a-z]{2,})(?:\/|\b)/i', $normalizedQuery, $domainMatches)) {
            foreach ($domainMatches[1] as $domain) {
                $parts = explode('.', Str::lower(trim((string) $domain)));
                if ($parts !== []) {
                    $candidates[] = (string) ($parts[0] ?? '');
                }
            }
        }

        if (preg_match_all('/"([^"]+)"/', $normalizedQuery, $quotedMatches)) {
            foreach ($quotedMatches[1] as $phrase) {
                $candidates[] = trim((string) $phrase);
            }
        }

        $segments = preg_split('/[,;|]/', $normalizedQuery) ?: [];
        $firstSegment = trim((string) ($segments[0] ?? ''));
        if ($firstSegment !== '' && str_word_count($firstSegment) <= 6) {
            $candidates[] = $firstSegment;
        }

        foreach (array_slice($keywords, 0, 4) as $keyword) {
            $candidates[] = $keyword;
        }

        if (str_word_count($normalizedQuery) <= 6 && mb_strlen($normalizedQuery) <= 80) {
            $candidates[] = $normalizedQuery;
        }

        return collect($candidates)
            ->map(fn (string $value): string => preg_replace('/\s+/', ' ', trim($value)) ?: '')
            ->filter(fn (string $value): bool => $value !== '' && mb_strlen($value) >= 2)
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankApolloPreviewRows(array $rows, string $query): array
    {
        usort($rows, function (array $left, array $right) use ($query): int {
            $rightScore = $this->apolloRelevanceScore($right, $query);
            $leftScore = $this->apolloRelevanceScore($left, $query);

            if ($rightScore !== $leftScore) {
                return $rightScore <=> $leftScore;
            }

            return $this->apolloRowQualityScore($right) <=> $this->apolloRowQualityScore($left);
        });

        return $rows;
    }

    private function apolloRelevanceScore(array $row, string $query): int
    {
        $keywords = $this->searchKeywords($query);
        if ($keywords === []) {
            return $this->apolloRowQualityScore($row);
        }

        $organization = $this->organizationPayload($row);
        $companyText = $this->normalizeSearchText(implode(' ', array_filter([
            $organization['name'] ?? null,
            $organization['industry'] ?? null,
            $organization['website_url'] ?? null,
            $organization['website'] ?? null,
            $row['organization_name'] ?? null,
            $row['company_name'] ?? null,
        ])));
        $locationText = $this->normalizeSearchText(implode(' ', array_filter([
            $organization['city'] ?? null,
            $organization['state'] ?? null,
            $organization['region'] ?? null,
            $organization['country'] ?? null,
            $row['city'] ?? null,
            $row['state'] ?? null,
            $row['country'] ?? null,
        ])));
        $titleText = $this->normalizeSearchText(implode(' ', array_filter([
            $row['title'] ?? null,
            $row['headline'] ?? null,
            data_get($row, 'person.title'),
            data_get($row, 'person.headline'),
        ])));
        $generalText = $this->normalizeSearchText(json_encode([
            $row['name'] ?? null,
            $row['first_name'] ?? null,
            $row['last_name'] ?? null,
            $row['email'] ?? null,
            $row['organization_name'] ?? null,
            $row['company_name'] ?? null,
            $organization['name'] ?? null,
            $organization['industry'] ?? null,
            $organization['city'] ?? null,
            $organization['state'] ?? null,
            $organization['country'] ?? null,
        ], JSON_UNESCAPED_UNICODE) ?: '');

        $score = 0;

        foreach ($keywords as $keyword) {
            if (str_contains($companyText, $keyword)) {
                $score += 7;
            }

            if (str_contains($locationText, $keyword)) {
                $score += 6;
            }

            if (str_contains($titleText, $keyword)) {
                $score += 5;
            }

            if (str_contains($generalText, $keyword)) {
                $score += 2;
            }
        }

        foreach ($this->apolloSearchQueries($row) as $candidateQuery) {
            $normalizedCandidate = $this->normalizeSearchText($candidateQuery);
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedCandidate, $keyword)) {
                    $score += 2;
                }
            }
        }

        if ($this->queryTargetsSystemChangeNeed($query)) {
            if ($this->rowLooksLikeSystemChangeBuyer($row)) {
                $score += 12;
            }

            if ($this->rowLooksLikeSmallBusiness($row)) {
                $score += 6;
            }
        }

        return $score;
    }

    /**
     * @param  array<int, array{successful: bool, rows: array<int, array<string, mixed>>, status: int, message: string, source: string}>  $failures
     */
    private function throwApolloSearchFailures(array $failures): void
    {
        if ($failures === []) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Apollo search preview failed unexpectedly.',
            ]);
        }

        if (collect($failures)->contains(fn (array $failure): bool => (int) ($failure['status'] ?? 0) === 429)) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'Apollo rate limit reached. Try again shortly.',
            ]);
        }

        $allAuthFailures = collect($failures)->every(
            fn (array $failure): bool => in_array((int) ($failure['status'] ?? 0), [401, 403], true)
        );

        if ($allAuthFailures) {
            $credentialFailureMessage = $this->authStrategy() === CampaignProspectProviderConnection::AUTH_METHOD_API_KEY
                ? 'Apollo rejected this API key or the key does not have access to People Search, Contacts Search, or Accounts Search.'
                : 'Apollo authorization has expired or no longer grants access to the required Apollo search endpoints.';

            throw ValidationException::withMessages([
                'provider_connection_id' => $credentialFailureMessage,
            ]);
        }

        throw ValidationException::withMessages([
            'provider_connection_id' => $this->firstNonEmptyString([
                collect($failures)
                    ->map(fn (array $failure): string => trim((string) ($failure['message'] ?? '')))
                    ->filter(fn (string $value): bool => $value !== '')
                    ->first(),
                'Apollo search preview failed unexpectedly.',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function apolloRequest(array $credentials): PendingRequest
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));

        $request = Http::acceptJson()
            ->timeout(20);

        if ($accessToken !== '') {
            return $request->withToken($accessToken);
        }

        if ($apiKey !== '') {
            return $request->withHeaders([
                'X-Api-Key' => $apiKey,
            ]);
        }

        return $request;
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

    private function extractResponseRows(Response $response): array
    {
        $payload = $response->json();
        $candidates = [
            $payload['people'] ?? null,
            $payload['contacts'] ?? null,
            $payload['accounts'] ?? null,
            $payload['organizations'] ?? null,
            $payload['matches'] ?? null,
            $payload['results'] ?? null,
            data_get($payload, 'data.people'),
            data_get($payload, 'data.contacts'),
            data_get($payload, 'data.accounts'),
            data_get($payload, 'data.organizations'),
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
            ?? (
                $this->looksLikeOrganizationRow($row)
                    ? $row
                    : null
            )
            ?? null;

        return is_array($organization) ? $organization : [];
    }

    private function apolloPreviewEntityId(array $row): string
    {
        return trim((string) (
            $row['__apollo_entity_person_id']
                ?? $row['__apollo_entity_contact_id']
                ?? data_get($row, 'person.id')
                ?? $row['person_id']
                ?? $row['id']
                ?? ''
        ));
    }

    private function apolloEnrichmentPersonId(array $row): string
    {
        return trim((string) (
            $row['__apollo_entity_person_id']
                ?? data_get($row, 'person.id')
                ?? $row['person_id']
                ?? ''
        ));
    }

    private function apolloContactId(array $row): string
    {
        return trim((string) (
            $row['__apollo_entity_contact_id']
                ?? (
                    in_array('contacts_search', $this->apolloSearchSources($row), true)
                    || in_array('account_contacts_search', $this->apolloSearchSources($row), true)
                        ? ($row['id'] ?? '')
                        : ''
                )
        ));
    }

    /**
     * @return array<int, string>
     */
    private function apolloSearchSources(array $row): array
    {
        return collect($row['__apollo_search_sources'] ?? [])
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function apolloSearchQueries(array $row): array
    {
        return collect($row['__apollo_search_queries'] ?? [])
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function apolloRowDedupKey(array $row): string
    {
        $previewEntityId = $this->apolloPreviewEntityId($row);
        if ($previewEntityId !== '') {
            return 'entity:'.$previewEntityId;
        }

        $email = Str::lower($this->firstNonEmptyString([
            $row['email'] ?? null,
            $row['work_email'] ?? null,
            data_get($row, 'person.email'),
            data_get($row, 'person.work_email'),
        ]));

        if ($email !== '') {
            return 'email:'.$email;
        }

        $linkedin = Str::lower($this->firstNonEmptyString([
            $row['linkedin_url'] ?? null,
            $row['linkedin_profile_url'] ?? null,
            data_get($row, 'person.linkedin_url'),
        ]));

        if ($linkedin !== '') {
            return 'linkedin:'.$linkedin;
        }

        $organization = $this->organizationPayload($row);
        $contactName = Str::lower($this->firstNonEmptyString([
            $row['name'] ?? null,
            $row['contact_name'] ?? null,
            data_get($row, 'person.name'),
            trim(sprintf('%s %s', (string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? ''))),
            trim(sprintf('%s %s', (string) data_get($row, 'person.first_name', ''), (string) data_get($row, 'person.last_name', ''))),
        ]));
        $organizationName = Str::lower(trim((string) ($organization['name'] ?? $row['organization_name'] ?? $row['company_name'] ?? '')));

        if ($contactName !== '' && $organizationName !== '') {
            return 'name_org:'.md5($contactName.'|'.$organizationName);
        }

        return 'row:'.md5(json_encode([
            $contactName,
            $organizationName,
            Str::lower(trim((string) ($organization['website_url'] ?? $organization['website'] ?? $row['website_url'] ?? $row['website'] ?? ''))),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function apolloRowQualityScore(array $row): int
    {
        $organization = $this->organizationPayload($row);
        $score = 0;

        if ($this->apolloEnrichmentPersonId($row) !== '') {
            $score += 6;
        }
        if ($this->apolloContactId($row) !== '') {
            $score += 3;
        }
        if ($this->firstNonEmptyString([$row['email'] ?? null, $row['work_email'] ?? null, data_get($row, 'person.email')]) !== '') {
            $score += 4;
        }
        if ($this->firstNonEmptyString([$row['phone'] ?? null, data_get($row, 'phone_numbers.0.sanitized_number'), data_get($row, 'person.phone')]) !== '') {
            $score += 4;
        }
        if ($this->firstNonEmptyString([$row['linkedin_url'] ?? null, data_get($row, 'person.linkedin_url')]) !== '') {
            $score += 2;
        }
        if ($this->firstNonEmptyString([$row['name'] ?? null, data_get($row, 'person.name')]) !== '') {
            $score += 2;
        }
        if ($this->firstNonEmptyString([$organization['name'] ?? null, $row['organization_name'] ?? null, $row['company_name'] ?? null]) !== '') {
            $score += 2;
        }
        if ($this->firstNonEmptyString([$organization['website_url'] ?? null, $organization['website'] ?? null, $row['website_url'] ?? null, $row['website'] ?? null]) !== '') {
            $score += 1;
        }
        if ($this->firstNonEmptyString([$row['title'] ?? null, data_get($row, 'person.title')]) !== '') {
            $score += 1;
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function searchKeywords(string $value): array
    {
        $normalized = $this->normalizeSearchText($value);
        if ($normalized === '') {
            return [];
        }

        $stopWords = [
            'a', 'an', 'and', 'are', 'at', 'business', 'businesses', 'by', 'companies', 'company', 'de', 'des',
            'du', 'en', 'et', 'for', 'from', 'in', 'la', 'le', 'les', 'of', 'on', 'or', 'pour', 'services', 'the',
            'to', 'with',
        ];

        return collect(preg_split('/\s+/', $normalized) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => $token !== '' && mb_strlen($token) >= 3 && ! in_array($token, $stopWords, true) && ! is_numeric($token))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSearchText(string $value): string
    {
        $ascii = Str::lower(Str::ascii(strip_tags($value)));

        return preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?: '';
    }

    private function extractBusinessSubject(string $query): string
    {
        $patterns = [
            '/^\s*(.+?)\s+companies?\s+in\s+.+$/i',
            '/^\s*(.+?)\s+business(?:es)?\s+in\s+.+$/i',
            '/^\s*entreprises?\s+de\s+(.+?)\s+(?:a|au|aux|dans)\s+.+$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                $value = preg_replace('/\s+/', ' ', trim((string) ($matches[1] ?? ''))) ?: '';

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function extractLocationPhrase(string $query): string
    {
        $patterns = [
            "/\\b(?:in|near|around)\\s+([\\pL0-9][\\pL0-9\\s'’-]{1,60}?)(?:\\s+(?:that|who|with|without|looking|searching|want|wants|wanting|need|needs|needing|trying|planning|ready|interested)\\b|$)/iu",
            "/\\b(?:a|au|aux|dans)\\s+([\\pL0-9][\\pL0-9\\s'’-]{1,60}?)(?:\\s+(?:qui|avec|sans|veut|veulent|cherchent|ayant|souhaite|souhaitent|besoin)\\b|$)/iu",
            "/\\b(?:in|near|around)\\s+([\\pL0-9][\\pL0-9\\s'’-]{1,60})$/iu",
            "/\\b(?:a|au|aux|dans)\\s+([\\pL0-9][\\pL0-9\\s'’-]{1,60})$/iu",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                $value = preg_replace('/\s+/', ' ', trim((string) ($matches[1] ?? ''))) ?: '';
                $value = preg_replace('/\s+(?:that|who|with|without|looking|searching|want|wants|wanting|need|needs|needing|trying|planning|ready|interested|qui|avec|sans|veut|veulent|cherchent|ayant|souhaite|souhaitent|besoin)\b.*$/iu', '', $value) ?: '';

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function queryHasDecisionMakerTitle(string $query): bool
    {
        return preg_match('/\b(owner|founder|ceo|president|director|manager|head|vp)\b/i', $query) === 1;
    }

    private function queryTargetsSystemChangeNeed(string $query): bool
    {
        return preg_match('/\b(system|systeme|logiciel|software|crm|outil|outils|workflow|process|processus|manual|manuelle|excel|spreadsheet|legacy|obsolete|unstable|instable|chaos|migrate|migration|replace|replacement|switch|changer|change|modernize|moderniser|digital|informatique)\b/i', $query) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function systemChangeRoleQueries(string $locationPhrase, string $subjectPhrase): array
    {
        $roles = [
            'owner',
            'founder',
            'operations manager',
            'office manager',
            'general manager',
            'dispatcher',
            'scheduler',
            'administrator',
        ];

        $queries = [];

        foreach ($roles as $role) {
            if ($locationPhrase !== '') {
                $queries[] = trim($locationPhrase.' '.$role);
            }

            if ($subjectPhrase !== '') {
                $queries[] = trim($subjectPhrase.' '.$role);
            }
        }

        if ($locationPhrase === '' && $subjectPhrase === '') {
            $queries = array_merge($queries, [
                'owner',
                'founder',
                'operations manager',
            ]);
        }

        return $queries;
    }

    private function rowLooksLikeSystemChangeBuyer(array $row): bool
    {
        $title = $this->normalizeSearchText(implode(' ', array_filter([
            $row['title'] ?? null,
            $row['headline'] ?? null,
            data_get($row, 'person.title'),
            data_get($row, 'person.headline'),
            $row['name'] ?? null,
        ])));

        return preg_match('/\b(owner|founder|operations|operator|office|admin|administrator|dispatch|dispatcher|scheduler|coordinator|general manager|manager|president)\b/', $title) === 1;
    }

    private function rowLooksLikeSmallBusiness(array $row): bool
    {
        $organization = $this->organizationPayload($row);
        $size = $this->normalizeCompanySize($organization['estimated_num_employees'] ?? $row['estimated_num_employees'] ?? null);

        return in_array($size, ['1-10', '11-50'], true);
    }

    private function looksLikeOrganizationRow(array $row): bool
    {
        return trim((string) ($row['name'] ?? '')) !== ''
            && $this->firstNonEmptyString([
                $row['website_url'] ?? null,
                $row['website'] ?? null,
                $row['industry'] ?? null,
                $row['estimated_num_employees'] ?? null,
                $row['city'] ?? null,
            ]) !== '';
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
