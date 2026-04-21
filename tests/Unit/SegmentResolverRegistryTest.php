<?php

use App\Models\SavedSegment;
use App\Services\Segments\Resolvers\CustomerSegmentResolver;
use App\Services\Segments\Resolvers\QuoteSegmentResolver;
use App\Services\Segments\Resolvers\RequestSegmentResolver;
use App\Services\Segments\SegmentResolverRegistry;

test('segment resolver registry exposes request customer and quote resolvers', function () {
    $registry = app(SegmentResolverRegistry::class);

    expect($registry->resolverFor(SavedSegment::MODULE_REQUEST))->toBeInstanceOf(RequestSegmentResolver::class)
        ->and($registry->resolverFor(SavedSegment::MODULE_CUSTOMER))->toBeInstanceOf(CustomerSegmentResolver::class)
        ->and($registry->resolverFor(SavedSegment::MODULE_QUOTE))->toBeInstanceOf(QuoteSegmentResolver::class);
});

test('segment resolver registry rejects unknown modules', function () {
    $registry = app(SegmentResolverRegistry::class);

    expect(fn () => $registry->resolverFor('unknown'))->toThrow(\InvalidArgumentException::class);
});
