<?php

namespace App\Services\CRM\Connectors;

use App\Services\CRM\Connectors\Contracts\CrmConnectorAdapter;
use InvalidArgumentException;

class CrmConnectorRegistry
{
    /**
     * @var array<string, CrmConnectorAdapter>
     */
    private array $adapters;

    public function __construct(
        GmailConnectorAdapter $gmail,
        OutlookConnectorAdapter $outlook,
    ) {
        $this->adapters = [
            $gmail->key() => $gmail,
            $outlook->key() => $outlook,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return collect($this->adapters)
            ->map(fn (CrmConnectorAdapter $adapter) => $adapter->definition())
            ->values()
            ->all();
    }

    public function adapter(string $connectorKey): CrmConnectorAdapter
    {
        $normalizedKey = strtolower(trim($connectorKey));
        $adapter = $this->adapters[$normalizedKey] ?? null;

        if (! $adapter) {
            throw new InvalidArgumentException(sprintf('Unsupported CRM connector [%s].', $connectorKey));
        }

        return $adapter;
    }
}
