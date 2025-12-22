<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformSetting;
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

        $maintenance = PlatformSetting::getValue('maintenance', [
            'enabled' => false,
            'message' => '',
        ]);
        $templates = PlatformSetting::getValue('templates', [
            'email_default' => '',
            'quote_default' => '',
            'invoice_default' => '',
        ]);

        return Inertia::render('SuperAdmin/Settings/Edit', [
            'maintenance' => $maintenance,
            'templates' => $templates,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SETTINGS_MANAGE);

        $validated = $request->validate([
            'maintenance.enabled' => 'required|boolean',
            'maintenance.message' => 'nullable|string|max:500',
            'templates.email_default' => 'nullable|string|max:5000',
            'templates.quote_default' => 'nullable|string|max:5000',
            'templates.invoice_default' => 'nullable|string|max:5000',
        ]);

        PlatformSetting::setValue('maintenance', [
            'enabled' => (bool) $validated['maintenance']['enabled'],
            'message' => $validated['maintenance']['message'] ?? '',
        ]);

        PlatformSetting::setValue('templates', [
            'email_default' => $validated['templates']['email_default'] ?? '',
            'quote_default' => $validated['templates']['quote_default'] ?? '',
            'invoice_default' => $validated['templates']['invoice_default'] ?? '',
        ]);

        $this->logAudit($request, 'platform_settings.updated');

        return redirect()->back()->with('success', 'Platform settings updated.');
    }
}
