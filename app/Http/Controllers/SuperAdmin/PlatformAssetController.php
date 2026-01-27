<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformAsset;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAssetController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:80'],
        ]);

        $query = PlatformAsset::query()->latest();
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('mime', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['tag'])) {
            $tag = $filters['tag'];
            $query->whereJsonContains('tags', $tag);
        }

        $assets = $query
            ->paginate(24)
            ->through(fn (PlatformAsset $asset) => $this->mapAsset($asset))
            ->withQueryString();

        return Inertia::render('SuperAdmin/Assets/Index', [
            'assets' => $assets,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'tag' => $filters['tag'] ?? '',
            ],
            'dashboard_url' => route('superadmin.dashboard'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:20480', 'mimetypes:image/*,application/pdf,video/*'],
            'tags' => ['nullable', 'string', 'max:160'],
            'alt' => ['nullable', 'string', 'max:160'],
        ]);

        $tags = $this->parseTags($validated['tags'] ?? null);
        $alt = trim((string) ($validated['alt'] ?? ''));

        foreach ($request->file('files', []) as $file) {
            if (!$file) {
                continue;
            }
            $path = $file->storePublicly('assets', ['disk' => 'public']);
            if (!$path) {
                continue;
            }

            PlatformAsset::create([
                'name' => $file->getClientOriginalName() ?: basename($path),
                'path' => $path,
                'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => (int) $file->getSize(),
                'tags' => $tags,
                'alt' => $alt !== '' ? $alt : null,
                'uploaded_by' => $request->user()?->id,
            ]);
        }

        return redirect()->back()->with('success', 'Assets uploaded.');
    }

    public function destroy(Request $request, PlatformAsset $asset): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        if ($asset->path) {
            Storage::disk('public')->delete($asset->path);
        }

        $asset->delete();

        return redirect()->back()->with('success', 'Asset deleted.');
    }

    public function list(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::PAGES_MANAGE);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:80'],
        ]);

        $query = PlatformAsset::query()->latest();
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('mime', 'like', "%{$search}%");
            });
        }
        if (!empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        $assets = $query->limit(120)->get()->map(fn (PlatformAsset $asset) => $this->mapAsset($asset));

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
        ];
    }
}
