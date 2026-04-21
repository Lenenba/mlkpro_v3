<?php

namespace App\Services\Campaigns\Providers;

use App\Models\CampaignProspectProviderConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ApolloApiKeyProspectProviderAdapter extends ApolloProspectProviderAdapter
{
    private const PEOPLE_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/mixed_people/api_search';

    private const CONTACTS_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/contacts/search';

    private const ACCOUNTS_SEARCH_ENDPOINT = 'https://api.apollo.io/api/v1/accounts/search';

    public function key(): string
    {
        return CampaignProspectProviderConnection::PROVIDER_APOLLO_API;
    }

    public function authStrategy(): string
    {
        return CampaignProspectProviderConnection::AUTH_METHOD_API_KEY;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'logo_key' => CampaignProspectProviderConnection::PROVIDER_APOLLO,
            'auth_strategy' => $this->authStrategy(),
            'short_description' => $this->shortDescription(),
            'connect_description' => $this->connectDescription(),
            'credential_fields' => $this->credentialFields(),
            'supports_redirect' => false,
            'supports_manual_credentials' => true,
            'supports_refresh' => true,
            'scopes' => [],
            'connect_button_label' => sprintf('Connect %s', $this->label()),
            'reconnect_button_label' => sprintf('Reconnect %s', $this->label()),
            'setup_required' => false,
            'setup_message' => null,
        ];
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
                'placeholder' => 'Paste your Apollo API key',
                'help' => 'Use a master API key or a key with People Search or Contacts Search access. Bulk Match is optional and used only when Apollo allows enrichment. Stored encrypted for this tenant only.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function refreshCredentials(array $credentials): array
    {
        return [
            'credentials' => $credentials,
            ...$this->validateCredentials($credentials),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, status: string, message: string, errors?: array<string, string>}
     */
    public function validateCredentials(array $credentials): array
    {
        $apiKey = trim((string) ($credentials['api_key'] ?? ''));

        if ($apiKey === '') {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                'message' => 'Apollo API key is required.',
                'errors' => [
                    'api_key' => 'Apollo API key is required.',
                ],
            ];
        }

        if (mb_strlen($apiKey) < 8) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                'message' => 'Apollo API key looks incomplete.',
                'errors' => [
                    'api_key' => 'Apollo API key looks incomplete.',
                ],
            ];
        }

        try {
            $responses = [
                $this->probeSearchEndpoint($apiKey, self::PEOPLE_SEARCH_ENDPOINT, [
                    'q_keywords' => 'owner',
                    'page' => 1,
                    'per_page' => 1,
                ]),
                $this->probeSearchEndpoint($apiKey, self::CONTACTS_SEARCH_ENDPOINT, [
                    'q_keywords' => 'owner',
                    'page' => 1,
                    'per_page' => 1,
                ]),
                $this->probeSearchEndpoint($apiKey, self::ACCOUNTS_SEARCH_ENDPOINT, [
                    'q_organization_name' => 'apollo',
                    'page' => 1,
                    'per_page' => 1,
                ]),
            ];

            if (collect($responses)->contains(fn (array $response): bool => $response['successful'])) {
                return [
                    'ok' => true,
                    'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
                    'message' => 'Apollo API key validated.',
                ];
            }

            if (collect($responses)->contains(fn (array $response): bool => (int) ($response['status'] ?? 0) === 429)) {
                return [
                    'ok' => false,
                    'status' => CampaignProspectProviderConnection::STATUS_RATE_LIMITED,
                    'message' => 'Apollo rate limit reached while validating this API key.',
                ];
            }

            $allAuthFailures = collect($responses)->every(
                fn (array $response): bool => in_array((int) ($response['status'] ?? 0), [401, 403], true)
            );

            if ($allAuthFailures) {
                return [
                    'ok' => false,
                    'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                    'message' => 'Apollo rejected this API key or the key does not have access to People Search, Contacts Search, or Accounts Search.',
                ];
            }

            $message = collect($responses)
                ->map(fn (array $response): string => trim((string) ($response['message'] ?? '')))
                ->filter(fn (string $value): bool => $value !== '')
                ->first() ?: 'Apollo API key validation failed unexpectedly.';

            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                'message' => $message,
            ];
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'status' => CampaignProspectProviderConnection::STATUS_ERROR,
                'message' => 'The Apollo API could not be reached while validating this API key.',
            ];
        }
    }

    protected function shortDescription(): string
    {
        return 'Connect Apollo with a tenant-owned API key so campaigns can search people through Apollo search fallbacks even when a key does not expose every prospecting endpoint.';
    }

    protected function connectDescription(): string
    {
        return 'Use an Apollo API key when you need direct Apollo search access for campaign prospecting. The app will try People Search first, then Contacts Search and account-assisted fallbacks when needed.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{successful: bool, status: int, message: string}
     */
    private function probeSearchEndpoint(string $apiKey, string $endpoint, array $payload): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->withHeaders([
                'X-Api-Key' => $apiKey,
            ])
            ->post($endpoint, $payload);

        return [
            'successful' => $response->successful(),
            'status' => $response->status(),
            'message' => $this->responseMessage($response, 'Apollo API key validation failed unexpectedly.'),
        ];
    }
}
