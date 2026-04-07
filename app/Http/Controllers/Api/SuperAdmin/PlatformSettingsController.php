<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Services\SuperAdminPlatformSettingsService;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;

class PlatformSettingsController extends BaseController
{
    public function show(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);

        return $this->jsonResponse([
            ...app(SuperAdminPlatformSettingsService::class)->formPayload(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);
        $isSuperadmin = (bool) $request->user()?->isSuperadmin();
        $settingsService = app(SuperAdminPlatformSettingsService::class);
        $validated = $request->validate($settingsService->validationRules());

        $settingsService->update($validated, $isSuperadmin);

        $this->logAudit($request, 'platform_settings.updated');

        return $this->jsonResponse(['message' => 'Platform settings updated.']);
    }
}
