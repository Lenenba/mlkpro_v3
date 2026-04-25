<?php

namespace App\Http\Controllers;

use App\Queries\Prospects\BuildProspectDashboardData;

class ProspectController extends RequestController
{
    protected function buildAnalyticsData(int $accountId): array
    {
        return app(BuildProspectDashboardData::class)->execute($accountId);
    }
}
