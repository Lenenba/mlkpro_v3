<?php

namespace Tests\Support;

use App\Data\Social\ReadSocialDeliveryStatusData;
use App\Data\Social\SocialDeliveryStatusResultData;
use App\Services\Social\Contracts\SocialDeliveryStatusGatewayInterface;
use Closure;
use LogicException;

final class FakeSocialDeliveryStatusGateway implements SocialDeliveryStatusGatewayInterface
{
    /**
     * @var array<int, ReadSocialDeliveryStatusData>
     */
    public array $reads = [];

    /**
     * @var array<int, SocialDeliveryStatusResultData>
     */
    private array $results;

    private ?Closure $beforeEachRead = null;

    public function __construct(SocialDeliveryStatusResultData ...$results)
    {
        $this->results = $results;
    }

    public function beforeEachRead(Closure $callback): self
    {
        $this->beforeEachRead = $callback;

        return $this;
    }

    public function readStatus(ReadSocialDeliveryStatusData $delivery): SocialDeliveryStatusResultData
    {
        $this->reads[] = $delivery;

        if ($this->beforeEachRead) {
            ($this->beforeEachRead)();
        }

        $result = array_shift($this->results);

        if (! $result) {
            throw new LogicException('No fake social delivery status result remains.');
        }

        return $result;
    }
}
