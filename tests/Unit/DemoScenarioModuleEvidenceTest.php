<?php

use App\Services\Demo\DemoScenarioModuleEvidence;

test('request and job demo modules resolve to their operational entry routes', function () {
    $routes = (new DemoScenarioModuleEvidence)->routeNames([
        'requests',
        'jobs',
        'requests',
    ]);

    expect($routes)->toBe([
        'requests' => 'service-requests.index',
        'jobs' => 'jobs.index',
    ]);
});
