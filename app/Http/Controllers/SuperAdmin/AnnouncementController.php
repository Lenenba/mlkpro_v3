<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformAnnouncement;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');

        $tenants = User::query()
            ->where('role_id', $ownerRoleId)
            ->orderBy('company_name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'company_name', 'created_at'])
            ->map(function (User $tenant) {
                $label = $tenant->company_name ?: $tenant->name ?: $tenant->email;

                return [
                    'id' => $tenant->id,
                    'label' => $label,
                    'email' => $tenant->email,
                    'created_at' => $tenant->created_at,
                ];
            });

        $announcements = PlatformAnnouncement::query()
            ->with(['tenants:id,name,email,company_name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (PlatformAnnouncement $announcement) {
                $tenantLabels = $announcement->tenants->map(function (User $tenant) {
                    return $tenant->company_name ?: $tenant->name ?: $tenant->email;
                });

                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'status' => $announcement->status,
                    'audience' => $announcement->audience,
                    'placement' => $announcement->placement,
                    'new_tenant_days' => $announcement->new_tenant_days,
                    'media_type' => $announcement->media_type,
                    'media_url' => $announcement->media_url,
                    'media_external_url' => $announcement->getRawOriginal('media_url'),
                    'media_path' => $announcement->media_path,
                    'link_label' => $announcement->link_label,
                    'link_url' => $announcement->link_url,
                    'priority' => $announcement->priority,
                    'starts_at' => $announcement->starts_at?->toDateString(),
                    'ends_at' => $announcement->ends_at?->toDateString(),
                    'tenant_ids' => $announcement->tenants->pluck('id')->values(),
                    'tenant_labels' => $tenantLabels->values(),
                    'created_at' => $announcement->created_at,
                ];
            });

        return Inertia::render('SuperAdmin/Announcements/Index', [
            'announcements' => $announcements,
            'tenants' => $tenants,
            'audiences' => PlatformAnnouncement::AUDIENCES,
            'placements' => PlatformAnnouncement::PLACEMENTS,
            'statuses' => PlatformAnnouncement::STATUSES,
            'media_types' => PlatformAnnouncement::MEDIA_TYPES,
        ]);
    }

    public function preview(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $topPlacements = ['internal', 'client', 'both'];
        $quickPlacements = ['quick_actions', 'both'];

        $announcements = PlatformAnnouncement::query()
            ->active()
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(function (PlatformAnnouncement $announcement) {
                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'placement' => $announcement->placement,
                    'media_type' => $announcement->media_type,
                    'media_url' => $announcement->media_url,
                    'link_label' => $announcement->link_label,
                    'link_url' => $announcement->link_url,
                    'starts_at' => $announcement->starts_at?->toDateString(),
                    'ends_at' => $announcement->ends_at?->toDateString(),
                ];
            });

        return Inertia::render('SuperAdmin/Announcements/Preview', [
            'topAnnouncements' => $announcements->filter(
                fn ($item) => in_array($item['placement'], $topPlacements, true)
            )->values(),
            'quickAnnouncements' => $announcements->filter(
                fn ($item) => in_array($item['placement'], $quickPlacements, true)
            )->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:5000',
            'status' => ['required', 'string', Rule::in(PlatformAnnouncement::STATUSES)],
            'audience' => ['required', 'string', Rule::in(PlatformAnnouncement::AUDIENCES)],
            'placement' => ['required', 'string', Rule::in(PlatformAnnouncement::PLACEMENTS)],
            'priority' => 'nullable|integer|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'new_tenant_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
                Rule::requiredIf(fn() => $request->input('audience') === 'new_tenants'),
            ],
            'media_type' => ['nullable', 'string', Rule::in(PlatformAnnouncement::MEDIA_TYPES)],
            'media_url' => 'nullable|url|max:2048',
            'media_file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,ogg|max:25600',
            'link_label' => 'nullable|string|max:120',
            'link_url' => 'nullable|url|max:2048',
            'tenant_ids' => [
                'nullable',
                'array',
                Rule::requiredIf(fn() => $request->input('audience') === 'tenants'),
            ],
            'tenant_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('role_id', $ownerRoleId),
            ],
        ]);

        $placement = $validated['placement'] ?? 'internal';
        $mediaType = $validated['media_type'] ?? 'none';
        $mediaUrl = $validated['media_url'] ?? null;
        $mediaPath = null;

        if ($request->hasFile('media_file')) {
            $file = $request->file('media_file');
            $mime = $file?->getMimeType() ?: '';
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
            $mediaPath = $file->store('platform-announcements', 'public');
            $mediaUrl = null;
        }

        if (!$mediaPath && !$mediaUrl) {
            $mediaType = 'none';
        }

        $announcement = PlatformAnnouncement::query()->create([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'status' => $validated['status'],
            'audience' => $validated['audience'],
            'placement' => $placement,
            'new_tenant_days' => $validated['new_tenant_days'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl,
            'media_path' => $mediaPath,
            'link_label' => $validated['link_label'] ?? null,
            'link_url' => $validated['link_url'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        if ($validated['audience'] === 'tenants') {
            $announcement->tenants()->sync($validated['tenant_ids'] ?? []);
        }

        $this->logAudit($request, 'platform_announcement.created', $announcement);

        return redirect()->back()->with('success', 'Announcement created.');
    }

    public function update(Request $request, PlatformAnnouncement $announcement): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:5000',
            'status' => ['required', 'string', Rule::in(PlatformAnnouncement::STATUSES)],
            'audience' => ['required', 'string', Rule::in(PlatformAnnouncement::AUDIENCES)],
            'placement' => ['required', 'string', Rule::in(PlatformAnnouncement::PLACEMENTS)],
            'priority' => 'nullable|integer|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'new_tenant_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
                Rule::requiredIf(fn() => $request->input('audience') === 'new_tenants'),
            ],
            'media_type' => ['nullable', 'string', Rule::in(PlatformAnnouncement::MEDIA_TYPES)],
            'media_url' => 'nullable|url|max:2048',
            'media_file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,ogg|max:25600',
            'clear_media' => 'nullable|boolean',
            'link_label' => 'nullable|string|max:120',
            'link_url' => 'nullable|url|max:2048',
            'tenant_ids' => [
                'nullable',
                'array',
                Rule::requiredIf(fn() => $request->input('audience') === 'tenants'),
            ],
            'tenant_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('role_id', $ownerRoleId),
            ],
        ]);

        $placement = $validated['placement'] ?? 'internal';
        $mediaType = $announcement->media_type;
        $mediaUrl = $announcement->getRawOriginal('media_url');
        $mediaPath = $announcement->media_path;
        $clearMedia = (bool) ($validated['clear_media'] ?? false);

        if ($clearMedia && $mediaPath) {
            Storage::disk('public')->delete($mediaPath);
            $mediaPath = null;
        }

        if ($request->hasFile('media_file')) {
            if ($mediaPath) {
                Storage::disk('public')->delete($mediaPath);
            }
            $file = $request->file('media_file');
            $mime = $file?->getMimeType() ?: '';
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
            $mediaPath = $file->store('platform-announcements', 'public');
            $mediaUrl = null;
        } elseif ($clearMedia) {
            $mediaType = $validated['media_type'] ?? 'none';
            $mediaUrl = $validated['media_url'] ?? null;
        } elseif (!$announcement->media_path) {
            $mediaType = $validated['media_type'] ?? $mediaType;
            $mediaUrl = $validated['media_url'] ?? null;
        }

        if (!$mediaPath && !$mediaUrl) {
            $mediaType = 'none';
        }

        $announcement->update([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'status' => $validated['status'],
            'audience' => $validated['audience'],
            'placement' => $placement,
            'new_tenant_days' => $validated['new_tenant_days'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl,
            'media_path' => $mediaPath,
            'link_label' => $validated['link_label'] ?? null,
            'link_url' => $validated['link_url'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
        ]);

        if ($validated['audience'] === 'tenants') {
            $announcement->tenants()->sync($validated['tenant_ids'] ?? []);
        } else {
            $announcement->tenants()->detach();
        }

        $this->logAudit($request, 'platform_announcement.updated', $announcement);

        return redirect()->back()->with('success', 'Announcement updated.');
    }

    public function destroy(Request $request, PlatformAnnouncement $announcement): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        if ($announcement->media_path) {
            Storage::disk('public')->delete($announcement->media_path);
        }

        $announcement->delete();

        $this->logAudit($request, 'platform_announcement.deleted', $announcement);

        return redirect()->back()->with('success', 'Announcement deleted.');
    }
}
