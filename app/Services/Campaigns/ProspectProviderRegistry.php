<?php

namespace App\Services\Campaigns;

use App\Services\Campaigns\Providers\ApolloProspectProviderAdapter;
use App\Services\Campaigns\Providers\Contracts\ProspectProviderAdapter;
use App\Services\Campaigns\Providers\LushaProspectProviderAdapter;
use App\Services\Campaigns\Providers\UpLeadProspectProviderAdapter;
use InvalidArgumentException;

class ProspectProviderRegistry
{
    /**
     * @var array<string, ProspectProviderAdapter>
     */
    private array $adapters;

    public function __construct(
        ApolloProspectProviderAdapter $apollo,
        LushaProspectProviderAdapter $lusha,
        UpLeadProspectProviderAdapter $upLead,
    ) {
        $this->adapters = [
            $apollo->key() => $apollo,
            $lusha->key() => $lusha,
            $upLead->key() => $upLead,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return collect($this->adapters)
            ->map(fn (ProspectProviderAdapter $adapter) => [
                'key' => $adapter->key(),
                'label' => $adapter->label(),
                'credential_fields' => $adapter->credentialFields(),
            ])
            ->values()
            ->all();
    }

    public function adapter(string $providerKey): ProspectProviderAdapter
    {
        $adapter = $this->adapters[$providerKey] ?? null;
        if (! $adapter) {
            throw new InvalidArgumentException(sprintf('Unsupported prospect provider [%s].', $providerKey));
        }

        return $adapter;
    }
}
