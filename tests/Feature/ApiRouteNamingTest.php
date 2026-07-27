<?php

use Illuminate\Routing\Route;

test('route names are globally unique and contain no empty api prefixes', function () {
    $namedRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn (Route $route) => $route->getName())
        ->filter()
        ->values();

    $duplicates = $namedRoutes
        ->countBy()
        ->filter(fn (int $count) => $count > 1)
        ->all();
    $emptyApiPrefixes = $namedRoutes
        ->filter(fn (string $name) => str_starts_with($name, 'api.') && str_ends_with($name, '.'))
        ->values()
        ->all();

    expect($duplicates)->toBe([])
        ->and($emptyApiPrefixes)->toBe([])
        ->and($namedRoutes)->not->toContain('api.')
        ->and($namedRoutes)->not->toContain('api.super-admin.');
});

test('explicit api route contracts keep their names and paths', function (string $name, string $path) {
    expect(route($name, absolute: false))->toBe($path);
})->with([
    'Stripe webhook' => ['api.stripe.webhook', '/api/v1/stripe/webhook'],
    'public pricing' => ['api.public.pricing', '/api/v1/public/pricing'],
    'request integration' => ['api.integrations.requests.store', '/api/v1/integrations/requests'],
    'CRM connector integration' => ['api.integrations.crm.connector_events.store', '/api/v1/integrations/crm/connector-events'],
    'finance approvals' => ['api.finance-approvals.index', '/api/v1/finance-approvals'],
    'products resource' => ['api.product.index', '/api/v1/product'],
    'services resource' => ['api.service.index', '/api/v1/service'],
    'customers resource' => ['api.customer.index', '/api/v1/customer'],
    'works resource' => ['api.work.index', '/api/v1/work'],
]);
