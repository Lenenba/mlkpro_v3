<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdminDashboardService;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends BaseSuperAdminController
{
    public function __construct(private SuperAdminDashboardService $dashboardService)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ANALYTICS_VIEW);

        $metrics = $this->dashboardService->getMetrics();
        $recentAudits = $this->dashboardService->getRecentAudits();

        return Inertia::render('SuperAdmin/Dashboard', [
            'metrics' => $metrics,
            'recent_audits' => $recentAudits,
        ]);
    }
}
