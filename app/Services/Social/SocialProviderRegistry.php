<?php

namespace App\Services\Social;

use App\Services\Social\Contracts\PlatformPublisherInterface;
use App\Services\Social\Providers\FacebookPagePlatformPublisher;
use App\Services\Social\Providers\InstagramBusinessPlatformPublisher;
use App\Services\Social\Providers\LinkedInPagePlatformPublisher;
use App\Services\Social\Providers\XProfilePlatformPublisher;
use InvalidArgumentException;

class SocialProviderRegistry
{
    /**
     * @var array<string, PlatformPublisherInterface>
     */
    private array $publishers;

    public function __construct(
        FacebookPagePlatformPublisher $facebook,
        InstagramBusinessPlatformPublisher $instagram,
        LinkedInPagePlatformPublisher $linkedin,
        XProfilePlatformPublisher $x,
    ) {
        $this->publishers = [
            $facebook->key() => $facebook,
            $instagram->key() => $instagram,
            $linkedin->key() => $linkedin,
            $x->key() => $x,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        return collect($this->publishers)
            ->map(fn (PlatformPublisherInterface $publisher) => $publisher->definition())
            ->values()
            ->all();
    }

    public function publisher(string $platform): PlatformPublisherInterface
    {
        $publisher = $this->publishers[$platform] ?? null;
        if (! $publisher) {
            throw new InvalidArgumentException(sprintf('Unsupported social platform [%s].', $platform));
        }

        return $publisher;
    }
}
