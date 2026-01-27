<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformSection;
use App\Models\User;
use App\Services\PlatformSectionContentService;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSectionController extends BaseSuperAdminController
{
    private const TYPES = ['generic', 'hero', 'features', 'faq', 'testimonials', 'cta', 'gallery'];

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformSectionContentService::class);

        $sections = PlatformSection::query()
            ->with(['updatedBy:id,name,email'])
            ->orderBy('name')
            ->get()
            ->map(function (PlatformSection $section) use ($service) {
                $meta = $service->meta($section);

                return [
                    'id' => $section->id,
                    'name' => $section->name,
                    'type' => $section->type,
                    'is_active' => $section->is_active,
                    'updated_at' => $meta['updated_at'] ?? optional($section->updated_at)->toIso8601String(),
                    'updated_by' => $section->updatedBy ? [
                        'id' => $section->updatedBy->id,
                        'name' => $section->updatedBy->name,
                        'email' => $section->updatedBy->email,
                    ] : null,
                ];
            })
            ->values();

        return Inertia::render('SuperAdmin/Sections/Index', [
            'sections' => $sections,
            'dashboard_url' => route('superadmin.dashboard'),
            'create_url' => route('superadmin.sections.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformSectionContentService::class);
        $locales = $service->locales();
        $defaultLocale = in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0];

        $section = new PlatformSection([
            'name' => '',
            'type' => 'generic',
            'is_active' => true,
            'content' => null,
        ]);

        return Inertia::render('SuperAdmin/Sections/Edit', [
            'mode' => 'create',
            'section' => [
                'id' => null,
                'name' => '',
                'type' => 'generic',
                'is_active' => true,
            ],
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'content' => $service->resolveAll($section),
            'meta' => [
                'updated_at' => null,
                'updated_by' => null,
            ],
            'dashboard_url' => route('superadmin.dashboard'),
            'index_url' => route('superadmin.sections.index'),
            'types' => self::TYPES,
            'asset_list_url' => route('superadmin.assets.list'),
            'ai_enabled' => (bool) config('services.openai.key'),
            'ai_image_generate_url' => route('superadmin.ai.images.generate'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformSectionContentService::class);
        $locales = $service->locales();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(self::TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'locale' => ['required', Rule::in($locales)],
            'content' => ['required', 'array'],
        ]);

        $section = PlatformSection::create([
            'name' => trim((string) $validated['name']),
            'type' => (string) $validated['type'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'updated_by' => $request->user()?->id,
        ]);

        $service->updateLocale($section, (string) $validated['locale'], (array) $validated['content'], $request->user()?->id);

        $this->logAudit($request, 'platform_section.created', $section, [
            'name' => $section->name,
        ]);

        return redirect()
            ->route('superadmin.sections.edit', $section)
            ->with('success', 'Section created.');
    }

    public function edit(Request $request, PlatformSection $section): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformSectionContentService::class);
        $locales = $service->locales();
        $defaultLocale = in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0];
        $meta = $service->meta($section);

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

        return Inertia::render('SuperAdmin/Sections/Edit', [
            'mode' => 'edit',
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'type' => $section->type,
                'is_active' => $section->is_active,
            ],
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'content' => $service->resolveAll($section),
            'meta' => [
                'updated_at' => $meta['updated_at'] ?? null,
                'updated_by' => $updatedBy,
            ],
            'dashboard_url' => route('superadmin.dashboard'),
            'index_url' => route('superadmin.sections.index'),
            'types' => self::TYPES,
            'asset_list_url' => route('superadmin.assets.list'),
            'ai_enabled' => (bool) config('services.openai.key'),
            'ai_image_generate_url' => route('superadmin.ai.images.generate'),
        ]);
    }

    public function update(Request $request, PlatformSection $section): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformSectionContentService::class);
        $locales = $service->locales();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(self::TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'locale' => ['required', Rule::in($locales)],
            'content' => ['required', 'array'],
        ]);

        $section->fill([
            'name' => trim((string) $validated['name']),
            'type' => (string) $validated['type'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'updated_by' => $request->user()?->id,
        ]);
        $section->save();

        $service->updateLocale($section, (string) $validated['locale'], (array) $validated['content'], $request->user()?->id);

        $this->logAudit($request, 'platform_section.updated', $section, [
            'name' => $section->name,
            'locale' => (string) $validated['locale'],
        ]);

        return redirect()->back()->with('success', 'Section updated.');
    }

    public function destroy(Request $request, PlatformSection $section): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $name = $section->name;
        $section->delete();

        $this->logAudit($request, 'platform_section.deleted', null, [
            'name' => $name,
        ]);

        return redirect()->route('superadmin.sections.index')->with('success', 'Section deleted.');
    }
}
