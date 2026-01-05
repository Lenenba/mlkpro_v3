<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Models\PlatformAnnouncement;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AnnouncementController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $tenants = User::query()
            ->when($ownerRoleId, fn ($builder) => $builder->where('role_id', $ownerRoleId))
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
                $tenantLabels = $announcement->tenants->map(fn (User $tenant) => $tenant->company_name ?: $tenant->name ?: $tenant->email);
                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'status' => $announcement->status,
                    'audience' => $announcement->audience,
                    'placement' => $announcement->placement,
                    'display_style' => $announcement->display_style,
                    'background_color' => $announcement->background_color,
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

        return $this->jsonResponse([
            'announcements' => $announcements,
            'tenants' => $tenants,
            'statuses' => PlatformAnnouncement::STATUSES,
            'audiences' => PlatformAnnouncement::AUDIENCES,
            'placements' => PlatformAnnouncement::PLACEMENTS,
            'display_styles' => PlatformAnnouncement::DISPLAY_STYLES,
            'media_types' => PlatformAnnouncement::MEDIA_TYPES,
        ]);
    }

    public function store(Request $request)
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
            'display_style' => ['nullable', 'string', Rule::in(PlatformAnnouncement::DISPLAY_STYLES)],
            'background_color' => ['nullable', 'string', 'max:20', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'new_tenant_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
                Rule::requiredIf(fn () => $request->input('audience') === 'new_tenants'),
            ],
            'media_type' => ['nullable', 'string', Rule::in(PlatformAnnouncement::MEDIA_TYPES)],
            'media_url' => 'nullable|url|max:2048',
            'link_label' => 'nullable|string|max:120',
            'link_url' => 'nullable|url|max:2048',
            'tenant_ids' => [
                'nullable',
                'array',
                Rule::requiredIf(fn () => $request->input('audience') === 'tenants'),
            ],
            'tenant_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('role_id', $ownerRoleId),
            ],
        ]);

        $mediaType = $validated['media_type'] ?? 'none';
        if (!$validated['media_url']) {
            $mediaType = 'none';
        }

        $announcement = PlatformAnnouncement::create([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'status' => $validated['status'],
            'audience' => $validated['audience'],
            'placement' => $validated['placement'],
            'display_style' => $validated['display_style'] ?? 'standard',
            'background_color' => $validated['background_color'] ?? null,
            'new_tenant_days' => $validated['new_tenant_days'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $validated['media_url'] ?? null,
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

        return $this->jsonResponse(['announcement_id' => $announcement->id], Response::HTTP_CREATED);
    }

    public function update(Request $request, PlatformAnnouncement $announcement)
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
            'display_style' => ['nullable', 'string', Rule::in(PlatformAnnouncement::DISPLAY_STYLES)],
            'background_color' => ['nullable', 'string', 'max:20', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'new_tenant_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365',
                Rule::requiredIf(fn () => $request->input('audience') === 'new_tenants'),
            ],
            'media_type' => ['nullable', 'string', Rule::in(PlatformAnnouncement::MEDIA_TYPES)],
            'media_url' => 'nullable|url|max:2048',
            'link_label' => 'nullable|string|max:120',
            'link_url' => 'nullable|url|max:2048',
            'clear_media' => 'nullable|boolean',
            'tenant_ids' => [
                'nullable',
                'array',
                Rule::requiredIf(fn () => $request->input('audience') === 'tenants'),
            ],
            'tenant_ids.*' => [
                'integer',
                Rule::exists('users', 'id')->where('role_id', $ownerRoleId),
            ],
        ]);

        $mediaUrl = $validated['media_url'] ?? $announcement->getRawOriginal('media_url');
        $mediaType = $validated['media_type'] ?? $announcement->media_type;
        if ((bool) ($validated['clear_media'] ?? false)) {
            if ($announcement->media_path) {
                Storage::disk('public')->delete($announcement->media_path);
            }
            $announcement->media_path = null;
            $mediaUrl = $validated['media_url'] ?? null;
            $mediaType = $mediaUrl ? ($mediaType === 'video' ? 'video' : 'image') : 'none';
        } elseif (!$mediaUrl) {
            $mediaType = 'none';
        }

        $announcement->update([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'status' => $validated['status'],
            'audience' => $validated['audience'],
            'placement' => $validated['placement'],
            'display_style' => $validated['display_style'] ?? $announcement->display_style,
            'background_color' => $validated['background_color'] ?? $announcement->background_color,
            'new_tenant_days' => $validated['new_tenant_days'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl,
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

        return $this->jsonResponse(['message' => 'Announcement updated.']);
    }

    public function destroy(Request $request, PlatformAnnouncement $announcement)
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        if ($announcement->media_path) {
            Storage::disk('public')->delete($announcement->media_path);
        }

        $announcement->delete();

        $this->logAudit($request, 'platform_announcement.deleted', $announcement);

        return $this->jsonResponse(['message' => 'Announcement deleted.']);
    }
}
