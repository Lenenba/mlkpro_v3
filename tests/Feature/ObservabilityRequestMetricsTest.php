<?php

use App\Services\Observability\RequestMetricsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('observability.enabled', true);
    config()->set('observability.cache.store', 'array');
    config()->set('observability.cache.prefix', 'phase8-tests-'.Str::lower(Str::random(12)));
    config()->set('observability.request.tracked_routes', ['phase8.ping']);

    Cache::store('array')->flush();
});

it('records request metrics for tracked web routes', function () {
    Route::middleware('web')->get('/phase8/ping', function () {
        usleep(20_000);

        return response('ok');
    })->name('phase8.ping');
    Route::getRoutes()->refreshNameLookups();

    $this->get('/phase8/ping')->assertOk();

    $routeSummary = collect(app(RequestMetricsService::class)->summary())
        ->firstWhere('route_name', 'phase8.ping');

    expect($routeSummary)->not->toBeNull()
        ->and($routeSummary['count_24h'])->toBe(1)
        ->and($routeSummary['p95_ms'])->toBeGreaterThan(0);
});
