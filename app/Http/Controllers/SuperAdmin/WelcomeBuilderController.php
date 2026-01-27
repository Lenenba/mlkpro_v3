<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\User;
use App\Services\WelcomeContentService;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeBuilderController extends BaseSuperAdminController
{
    public function edit(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::WELCOME_MANAGE);

        $service = app(WelcomeContentService::class);
        $locales = $service->locales();
        $defaultLocale = in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0];
        $meta = $service->meta();

        $updatedBy = null;
        if (!empty($meta['updated_by'])) {
            $user = User::query()->select(['id', 'name', 'email'])->find($meta['updated_by']);
            if ($user) {
                $updatedBy = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            }
        }

        return Inertia::render('SuperAdmin/WelcomeBuilder/Edit', [
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'content' => $service->resolveAll(),
            'meta' => [
                'updated_at' => $meta['updated_at'] ?? null,
                'updated_by' => $updatedBy,
            ],
            'preview_url' => route('superadmin.dashboard'),
            'ai_enabled' => (bool) config('services.openai.key'),
            'ai_image_generate_url' => route('superadmin.ai.images.generate'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::WELCOME_MANAGE);

        $service = app(WelcomeContentService::class);
        $locales = $service->locales();

        $validated = $request->validate([
            'locale' => ['required', Rule::in($locales)],
            'content' => ['required', 'array'],
            'hero_image' => ['nullable', 'image', 'max:5120'],
            'hero_image_remove' => ['nullable', 'boolean'],
            'workflow_image' => ['nullable', 'image', 'max:5120'],
            'workflow_image_remove' => ['nullable', 'boolean'],
            'field_image' => ['nullable', 'image', 'max:5120'],
            'field_image_remove' => ['nullable', 'boolean'],
        ]);

        $locale = (string) $validated['locale'];
        $uploads = [
            'hero_image' => $request->file('hero_image'),
            'hero_image_remove' => (bool) ($validated['hero_image_remove'] ?? false),
            'workflow_image' => $request->file('workflow_image'),
            'workflow_image_remove' => (bool) ($validated['workflow_image_remove'] ?? false),
            'field_image' => $request->file('field_image'),
            'field_image_remove' => (bool) ($validated['field_image_remove'] ?? false),
        ];

        $service->updateLocale($locale, (array) $validated['content'], $uploads, $request->user()?->id);

        $this->logAudit($request, 'welcome_builder.updated', null, [
            'locale' => $locale,
        ]);

        return redirect()->back()->with('success', 'Welcome page updated.');
    }
}
