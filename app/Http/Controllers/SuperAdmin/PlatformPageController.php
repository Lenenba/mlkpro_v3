<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use App\Models\User;
use App\Services\PlatformPageContentService;
use App\Services\PlatformSectionContentService;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlatformPageController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $pages = PlatformPage::query()
            ->with(['updatedBy:id,name,email'])
            ->orderBy('slug')
            ->get()
            ->map(function (PlatformPage $page) {
                $meta = app(PlatformPageContentService::class)->meta($page);

                return [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'is_active' => $page->is_active,
                    'updated_at' => $meta['updated_at'] ?? optional($page->updated_at)->toIso8601String(),
                    'updated_by' => $page->updatedBy ? [
                        'id' => $page->updatedBy->id,
                        'name' => $page->updatedBy->name,
                        'email' => $page->updatedBy->email,
                    ] : null,
                    'public_url' => route('public.pages.show', ['slug' => $page->slug]),
                ];
            })
            ->values();

        return Inertia::render('SuperAdmin/Pages/Index', [
            'pages' => $pages,
            'dashboard_url' => route('superadmin.dashboard'),
            'create_url' => route('superadmin.pages.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformPageContentService::class);
        $sectionService = app(PlatformSectionContentService::class);
        $locales = $service->locales();
        $defaultLocale = in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0];

        $page = new PlatformPage([
            'slug' => '',
            'title' => '',
            'is_active' => true,
            'content' => null,
        ]);

        return Inertia::render('SuperAdmin/Pages/Edit', [
            'mode' => 'create',
            'page' => [
                'id' => null,
                'slug' => '',
                'title' => '',
                'is_active' => true,
            ],
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'content' => $service->resolveAll($page),
            'theme' => $service->resolveTheme($page),
            'meta' => [
                'updated_at' => null,
                'updated_by' => null,
            ],
            'dashboard_url' => route('superadmin.dashboard'),
            'index_url' => route('superadmin.pages.index'),
            'public_url' => null,
            'library_sections' => $this->mapLibrarySections($sectionService),
            'library_index_url' => route('superadmin.sections.index'),
            'asset_list_url' => route('superadmin.assets.list'),
            'ai_enabled' => (bool) config('services.openai.key'),
            'ai_image_generate_url' => route('superadmin.ai.images.generate'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformPageContentService::class);
        $locales = $service->locales();

        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
            'locale' => ['required', Rule::in($locales)],
            'content' => ['required', 'array'],
            'theme' => ['nullable', 'array'],
        ]);

        $slug = $this->normalizeSlug($validated['slug']);
        if ($slug === '') {
            return back()->withErrors(['slug' => 'Invalid slug.'])->withInput();
        }

        if ($this->slugExists($slug)) {
            return back()->withErrors(['slug' => 'Slug already in use.'])->withInput();
        }

        $page = PlatformPage::create([
            'slug' => $slug,
            'title' => trim((string) $validated['title']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'updated_by' => $request->user()?->id,
        ]);

        $service->updateLocale(
            $page,
            (string) $validated['locale'],
            (array) $validated['content'],
            $request->user()?->id,
            $validated['theme'] ?? null
        );

        $this->logAudit($request, 'platform_page.created', $page, [
            'slug' => $page->slug,
        ]);

        return redirect()
            ->route('superadmin.pages.edit', $page)
            ->with('success', 'Page created.');
    }

    public function edit(Request $request, PlatformPage $page): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformPageContentService::class);
        $sectionService = app(PlatformSectionContentService::class);
        $locales = $service->locales();
        $defaultLocale = in_array(app()->getLocale(), $locales, true) ? app()->getLocale() : $locales[0];
        $meta = $service->meta($page);

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

        return Inertia::render('SuperAdmin/Pages/Edit', [
            'mode' => 'edit',
            'page' => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'is_active' => $page->is_active,
            ],
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'content' => $service->resolveAll($page),
            'theme' => $service->resolveTheme($page),
            'meta' => [
                'updated_at' => $meta['updated_at'] ?? null,
                'updated_by' => $updatedBy,
            ],
            'dashboard_url' => route('superadmin.dashboard'),
            'index_url' => route('superadmin.pages.index'),
            'public_url' => route('public.pages.show', ['slug' => $page->slug]),
            'library_sections' => $this->mapLibrarySections($sectionService),
            'library_index_url' => route('superadmin.sections.index'),
            'asset_list_url' => route('superadmin.assets.list'),
            'ai_enabled' => (bool) config('services.openai.key'),
            'ai_image_generate_url' => route('superadmin.ai.images.generate'),
        ]);
    }

    public function update(Request $request, PlatformPage $page): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $service = app(PlatformPageContentService::class);
        $locales = $service->locales();

        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
            'locale' => ['required', Rule::in($locales)],
            'content' => ['required', 'array'],
            'theme' => ['nullable', 'array'],
        ]);

        $slug = $this->normalizeSlug($validated['slug']);
        if ($slug === '') {
            return back()->withErrors(['slug' => 'Invalid slug.'])->withInput();
        }

        if ($this->slugExists($slug, $page->id)) {
            return back()->withErrors(['slug' => 'Slug already in use.'])->withInput();
        }

        $page->fill([
            'slug' => $slug,
            'title' => trim((string) $validated['title']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'updated_by' => $request->user()?->id,
        ]);
        $page->save();

        $service->updateLocale(
            $page,
            (string) $validated['locale'],
            (array) $validated['content'],
            $request->user()?->id,
            $validated['theme'] ?? null
        );

        $this->logAudit($request, 'platform_page.updated', $page, [
            'slug' => $page->slug,
            'locale' => (string) $validated['locale'],
        ]);

        return redirect()->back()->with('success', 'Page updated.');
    }

    public function destroy(Request $request, PlatformPage $page): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $slug = $page->slug;
        $page->delete();

        $this->logAudit($request, 'platform_page.deleted', null, [
            'slug' => $slug,
        ]);

        return redirect()->route('superadmin.pages.index')->with('success', 'Page deleted.');
    }

    private function normalizeSlug(string $value): string
    {
        return Str::slug($value);
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return PlatformPage::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function mapLibrarySections(PlatformSectionContentService $sectionService): array
    {
        return PlatformSection::query()
            ->orderBy('name')
            ->get()
            ->map(function (PlatformSection $section) use ($sectionService) {
                return [
                    'id' => $section->id,
                    'name' => $section->name,
                    'type' => $section->type,
                    'is_active' => $section->is_active,
                    'content' => $sectionService->resolveAll($section),
                ];
            })
            ->values()
            ->all();
    }
}
