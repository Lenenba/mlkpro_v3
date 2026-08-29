<?php

namespace App\Services\Social\Buffer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class BufferGraphqlClient
{
    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const ACCOUNT_QUERY = <<<'GRAPHQL'
        query MalikiaPulseBufferAccount {
          account {
            id
            name
            organizations {
              id
              name
            }
          }
        }
        GRAPHQL;

    private const CHANNELS_QUERY = <<<'GRAPHQL'
        query MalikiaPulseBufferChannels($input: ChannelsInput!) {
          channels(input: $input) {
            id
            organizationId
            name
            displayName
            service
            type
            isDisconnected
            isLocked
            isQueuePaused
            timezone
            scopes
            allowedActions
          }
        }
        GRAPHQL;

    public function isConfigured(): bool
    {
        return trim((string) config('services.buffer.local_connector.access_token')) !== '';
    }

    /**
     * @return array{id: string, name: ?string, organizations: list<array{id: string, name: string}>}
     */
    public function account(): array
    {
        $data = $this->execute(self::ACCOUNT_QUERY);
        $account = $data['account'] ?? null;

        if (! is_array($account) || ! is_array($account['organizations'] ?? null)) {
            $this->invalidPayload();
        }

        $organizations = [];

        foreach ($account['organizations'] as $organization) {
            if (! is_array($organization)) {
                $this->invalidPayload();
            }

            $organizations[] = [
                'id' => $this->requiredIdentifier($organization['id'] ?? null),
                'name' => $this->requiredString($organization['name'] ?? null),
            ];
        }

        return [
            'id' => $this->requiredIdentifier($account['id'] ?? null),
            'name' => $this->nullableString($account['name'] ?? null),
            'organizations' => $organizations,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     organization_id: string,
     *     name: string,
     *     display_name: ?string,
     *     service: string,
     *     type: string,
     *     is_disconnected: bool,
     *     is_locked: bool,
     *     is_queue_paused: bool,
     *     timezone: string,
     *     scopes: list<string>,
     *     allowed_actions: list<string>
     * }>
     */
    public function channels(string $organizationId): array
    {
        $organizationId = $this->requiredIdentifier($organizationId);
        $data = $this->execute(self::CHANNELS_QUERY, [
            'input' => [
                'organizationId' => $organizationId,
            ],
        ]);
        $payload = $data['channels'] ?? null;

        if (! is_array($payload) || ! array_is_list($payload)) {
            $this->invalidPayload();
        }

        $channels = [];

        foreach ($payload as $channel) {
            if (! is_array($channel)) {
                $this->invalidPayload();
            }

            $channels[] = [
                'id' => $this->requiredIdentifier($channel['id'] ?? null),
                'organization_id' => $this->requiredIdentifier($channel['organizationId'] ?? null),
                'name' => $this->requiredString($channel['name'] ?? null),
                'display_name' => $this->nullableString($channel['displayName'] ?? null),
                'service' => $this->requiredString($channel['service'] ?? null),
                'type' => $this->requiredString($channel['type'] ?? null),
                'is_disconnected' => $this->requiredBoolean($channel['isDisconnected'] ?? null),
                'is_locked' => $this->requiredBoolean($channel['isLocked'] ?? null),
                'is_queue_paused' => $this->requiredBoolean($channel['isQueuePaused'] ?? null),
                'timezone' => $this->requiredString($channel['timezone'] ?? null),
                'scopes' => $this->stringList($channel['scopes'] ?? null),
                'allowed_actions' => $this->stringList($channel['allowedActions'] ?? null),
            ];
        }

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function execute(string $query, array $variables = []): array
    {
        $accessToken = trim((string) config('services.buffer.local_connector.access_token'));

        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'buffer' => 'La clé API Buffer locale n’est pas configurée.',
            ]);
        }

        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->post((string) config('services.buffer.local_connector.api_url'), [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'buffer' => 'Buffer est momentanément inaccessible depuis cette machine.',
            ]);
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw ValidationException::withMessages([
                'buffer' => 'La réponse Buffer dépasse la taille autorisée.',
            ]);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw ValidationException::withMessages([
                'buffer' => 'Buffer a refusé la clé API locale. Créez ou remplacez la clé dans Buffer.',
            ]);
        }

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'buffer' => 'Le quota Buffer est temporairement atteint. Réessayez plus tard.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'buffer' => 'Buffer n’a pas pu traiter la demande locale.',
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->invalidPayload();
        }

        if (is_array($payload['errors'] ?? null) && $payload['errors'] !== []) {
            throw ValidationException::withMessages([
                'buffer' => 'Buffer a refusé la requête de découverte.',
            ]);
        }

        if (! is_array($payload['data'] ?? null)) {
            $this->invalidPayload();
        }

        return $payload['data'];
    }

    private function connectTimeout(): int
    {
        return max(1, min(10, (int) config('services.buffer.local_connector.connect_timeout', 5)));
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) config('services.buffer.local_connector.timeout', 10)));
    }

    private function requiredIdentifier(mixed $value): string
    {
        $identifier = $this->nullableString($value);

        if ($identifier === null || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $identifier) !== 1) {
            $this->invalidPayload();
        }

        return $identifier;
    }

    private function requiredString(mixed $value): string
    {
        $normalized = $this->nullableString($value);

        if ($normalized === null) {
            $this->invalidPayload();
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $this->invalidPayload();
        }

        $normalized = trim($value);

        if ($normalized === '' || mb_strlen($normalized) > 191 || preg_match('/\p{Cc}/u', $normalized) !== 0) {
            $this->invalidPayload();
        }

        return $normalized;
    }

    private function requiredBoolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            $this->invalidPayload();
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->invalidPayload();
        }

        $strings = [];

        foreach ($value as $item) {
            if ($item === null) {
                continue;
            }

            $strings[] = $this->requiredString($item);
        }

        return array_values(array_unique($strings));
    }

    private function invalidPayload(): never
    {
        throw ValidationException::withMessages([
            'buffer' => 'Buffer a renvoyé une réponse inattendue.',
        ]);
    }
}
