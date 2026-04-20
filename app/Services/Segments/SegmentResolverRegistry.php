<?php

namespace App\Services\Segments;

use App\Models\SavedSegment;
use App\Services\Segments\Contracts\SegmentModuleResolver;
use App\Services\Segments\Resolvers\CustomerSegmentResolver;
use App\Services\Segments\Resolvers\QuoteSegmentResolver;
use App\Services\Segments\Resolvers\RequestSegmentResolver;
use InvalidArgumentException;

class SegmentResolverRegistry
{
    /**
     * @var array<string, SegmentModuleResolver>
     */
    private array $resolvers;

    public function __construct(
        RequestSegmentResolver $request,
        CustomerSegmentResolver $customer,
        QuoteSegmentResolver $quote,
    ) {
        $this->resolvers = [
            $request->key() => $request,
            $customer->key() => $customer,
            $quote->key() => $quote,
        ];
    }

    public function resolverFor(string $module): SegmentModuleResolver
    {
        if (! array_key_exists($module, $this->resolvers)) {
            throw new InvalidArgumentException(sprintf('Unknown segment resolver module [%s].', $module));
        }

        return $this->resolvers[$module];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(SavedSegment $segment): array
    {
        return $this->resolverFor((string) $segment->module)->resolve($segment);
    }
}
