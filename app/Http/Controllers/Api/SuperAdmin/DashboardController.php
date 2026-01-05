<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Services\SuperAdminDashboardService;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;

class DashboardController extends BaseController
{
    public function __construct(private SuperAdminDashboardService $dashboardService)
    {
    }

    public function index(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::ANALYTICS_VIEW);

        return $this->jsonResponse([
            'metrics' => $this->dashboardService->getMetrics(),
            'recent_audits' => $this->dashboardService->getRecentAudits(),
        ]);
    }
}
