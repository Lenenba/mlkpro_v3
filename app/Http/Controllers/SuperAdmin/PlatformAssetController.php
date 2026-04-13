<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformAsset;
use App\Services\PlatformStockAssetCatalog;
use App\Support\PlatformPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAssetController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizeAnyPermission($request, [PlatformPermissions::PAGES_MANAGE, PlatformPermissions::WELCOME_MANAGE]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', Rule::in($this->dataTablePerPageOptions())],
        ]);

        $assets = $this->paginateAssets(
            $this->filteredAssets($filters, app()->getLocale()),
            $this->resolveDataTablePerPage($filters['per_page'] ?? null),
            $request
        );

        return Inertia::render('SuperAdmin/Assets/Index', [
            'assets' => $assets,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'tag' => $filters['tag'] ?? '',
                'per_page' => $this->resolveDataTablePerPage($filters['per_page'] ?? null),
            ],
            'dashboard_url' => route('superadmin.dashboard'),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeAnyPermission($request, [PlatformPermissions::PAGES_MANAGE, PlatformPermissions::WELCOME_MANAGE]);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:20480', 'mimetypes:image/*,application/pdf,video/*'],
            'tags' => ['nullable', 'string', 'max:160'],
            'alt' => ['nullable', 'string', 'max:160'],
        ]);

        $tags = $this->parseTags($validated['tags'] ?? null);
        $alt = trim((string) ($validated['alt'] ?? ''));

        $createdAssets = [];

        foreach ($request->file('files', []) as $file) {
            if (! $file) {
                continue;
            }
            $path = $file->storePublicly('assets', ['disk' => 'public']);
            if (! $path) {
                continue;
            }

            $asset = PlatformAsset::create([
                'name' => $file->getClientOriginalName() ?: basename($path),
                'path' => $path,
                'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => (int) $file->getSize(),
                'tags' => $tags,
                'alt' => $alt !== '' ? $alt : null,
                'uploaded_by' => $request->user()?->id,
            ]);

            $createdAssets[] = $asset;
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'message' => 'Assets uploaded.',
                'assets' => array_map(fn (PlatformAsset $asset) => $this->mapAsset($asset), $createdAssets),
            ]);
        }

        return redirect()->back()->with('success', 'Assets uploaded.');
    }

    public function destroy(Request $request, PlatformAsset $asset): RedirectResponse
    {
        $this->authorizeAnyPermission($request, [PlatformPermissions::PAGES_MANAGE, PlatformPermissions::WELCOME_MANAGE]);

        if ($this->isSystemAssetPath($asset->path)) {
            return redirect()->back()->withErrors(['asset' => 'Platform stock images cannot be deleted.']);
        }

        if ($asset->path) {
            Storage::disk('public')->delete($asset->path);
        }

        $asset->delete();

        return redirect()->back()->with('success', 'Asset deleted.');
    }

    public function list(Request $request)
    {
        $this->authorizeAnyPermission($request, [PlatformPermissions::PAGES_MANAGE, PlatformPermissions::WELCOME_MANAGE]);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:80'],
        ]);

        $assets = $this->filteredAssets($filters, app()->getLocale())
            ->take(120)
            ->values();

        return response()->json([
            'assets' => $assets,
        ]);
    }

    private function parseTags(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $tags = array_map(
            fn ($tag) => trim(mb_strtolower((string) $tag)),
            preg_split('/[,]+/', $value) ?: []
        );

        $tags = array_filter($tags, fn ($tag) => $tag !== '');

        return array_values(array_unique($tags));
    }

    private function mapAsset(PlatformAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'url' => Storage::disk('public')->url($asset->path),
            'mime' => $asset->mime,
            'size' => $asset->size,
            'tags' => $asset->tags ?? [],
            'alt' => $asset->alt,
            'is_image' => str_starts_with((string) $asset->mime, 'image/'),
            'created_at' => $asset->created_at?->toIso8601String(),
            'is_system' => $this->isSystemAssetPath($asset->path),
        ];
    }

    private function filteredAssets(array $filters, ?string $locale = null): Collection
    {
        $search = trim(mb_strtolower((string) ($filters['search'] ?? '')));
        $tag = trim(mb_strtolower((string) ($filters['tag'] ?? '')));

        $uploadedAssets = PlatformAsset::query()
            ->latest()
            ->get()
            ->map(fn (PlatformAsset $asset) => $this->mapAsset($asset));

        $systemAssets = app(PlatformStockAssetCatalog::class)->all($locale);

        return collect($uploadedAssets->all())
            ->merge($systemAssets)
            ->filter(function (array $asset) use ($search, $tag) {
                if ($search !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($asset['name'] ?? ''),
                        (string) ($asset['mime'] ?? ''),
                        (string) ($asset['alt'] ?? ''),
                        (string) ($asset['url'] ?? ''),
                    ])));

                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }

                if ($tag !== '') {
                    $tags = array_map(
                        fn ($value) => trim(mb_strtolower((string) $value)),
                        is_array($asset['tags'] ?? null) ? $asset['tags'] : []
                    );

                    if (! in_array($tag, $tags, true)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy([
                ['is_system', 'asc'],
                ['created_at', 'desc'],
                ['name', 'asc'],
            ])
            ->values();
    }

    private function paginateAssets(Collection $assets, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));
        $items = $assets->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $assets->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function isSystemAssetPath(?string $path): bool
    {
        $path = trim((string) $path);

        return str_starts_with($path, '/images/');
    }
}
