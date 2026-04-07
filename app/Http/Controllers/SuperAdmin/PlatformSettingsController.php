<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\SuperAdminPlatformSettingsService;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSettingsController extends BaseSuperAdminController
{
    public function edit(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);

        return Inertia::render('SuperAdmin/Settings/Edit', [
            ...app(SuperAdminPlatformSettingsService::class)->formPayload(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);
        $isSuperadmin = (bool) $request->user()?->isSuperadmin();
        $settingsService = app(SuperAdminPlatformSettingsService::class);
        $validated = $request->validate($settingsService->validationRules());

        $settingsService->update($validated, $isSuperadmin);

        $this->logAudit($request, 'platform_settings.updated');

        return redirect()->back()->with('success', 'Platform settings updated.');
    }
}
