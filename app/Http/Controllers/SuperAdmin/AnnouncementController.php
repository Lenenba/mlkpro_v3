<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Concerns\HandlesPlatformAnnouncementContent;
use App\Models\PlatformAnnouncement;
use App\Models\Role;
use App\Models\User;
use App\Support\LocalePreference;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends BaseSuperAdminController
{
    use HandlesPlatformAnnouncementContent;

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $locale = LocalePreference::forRequest($request);
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
            ->map(function (PlatformAnnouncement $announcement) use ($locale) {
                $tenantLabels = $announcement->tenants->map(function (User $tenant) {
                    return $tenant->company_name ?: $tenant->name ?: $tenant->email;
                });
                $localizedContent = $announcement->localizedContent($locale);

                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'translations' => $announcement->translations ?? [],
                    'localized_title' => $localizedContent['title'],
                    'localized_body' => $localizedContent['body'],
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
                    'localized_link_label' => $localizedContent['link_label'],
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
            'display_styles' => PlatformAnnouncement::DISPLAY_STYLES,
            'content_locales' => PlatformAnnouncement::CONTENT_LOCALES,
        ]);
    }

    public function preview(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ANNOUNCEMENTS_MANAGE);

        $locale = LocalePreference::forRequest($request);
        $topPlacements = ['internal', 'client', 'both'];
        $quickPlacements = ['quick_actions', 'both'];

        $announcements = PlatformAnnouncement::query()
            ->active()
            ->orderedForDisplay()
            ->limit(8)
            ->get()
            ->map(function (PlatformAnnouncement $announcement) use ($locale) {
                $content = $announcement->localizedContent($locale);

                return [
                    'id' => $announcement->id,
                    'title' => $content['title'],
                    'body' => $content['body'],
                    'placement' => $announcement->placement,
                    'display_style' => $announcement->display_style,
                    'background_color' => $announcement->background_color,
                    'media_type' => $announcement->media_type,
                    'media_url' => $announcement->media_url,
                    'link_label' => $content['link_label'],
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
            ...$this->announcementContentRules(),
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
            'media_file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,ogg|max:25600',
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

        $content = $this->resolveAnnouncementContent($validated);

        $placement = $validated['placement'] ?? 'internal';
        $displayStyle = $validated['display_style'] ?? 'standard';
        $backgroundColor = ($validated['background_color'] ?? null) ?: null;
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

        if (! $mediaPath && ! $mediaUrl) {
            $mediaType = 'none';
        }

        $this->ensureMediaOnlyHasUsableMedia($displayStyle, $mediaType, $mediaUrl, $mediaPath);

        if ($displayStyle === 'media_only') {
            $backgroundColor = null;
        }

        $announcement = PlatformAnnouncement::query()->create([
            'title' => $content['title'],
            'body' => $content['body'],
            'translations' => $content['translations'],
            'status' => $validated['status'],
            'audience' => $validated['audience'],
            'placement' => $placement,
            'display_style' => $displayStyle,
            'background_color' => $backgroundColor,
            'new_tenant_days' => $validated['new_tenant_days'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl,
            'media_path' => $mediaPath,
            'link_label' => $content['link_label'],
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
            ...$this->announcementContentRules(),
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
            'media_file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,ogg|max:25600',
            'clear_media' => 'nullable|boolean',
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

        $content = $this->resolveAnnouncementContent($validated, $announcement);

        $placement = $validated['placement'] ?? 'internal';
        $displayStyle = array_key_exists('display_style', $validated)
            ? ($validated['display_style'] ?? 'standard')
            : ($announcement->display_style ?? 'standard');
        $backgroundColor = array_key_exists('background_color', $validated)
            ? ($validated['background_color'] ?: null)
            : $announcement->background_color;
        $mediaType = $announcement->media_type;
        $mediaUrl = $announcement->getRawOriginal('media_url');
        $mediaPath = $announcement->media_path;
        $originalMediaPath = $mediaPath;
        $clearMedia = (bool) ($validated['clear_media'] ?? false);

        if ($request->hasFile('media_file')) {
            $file = $request->file('media_file');
            $mime = $file?->getMimeType() ?: '';
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
            $mediaPath = '__pending_upload__';
            $mediaUrl = null;
        } elseif ($clearMedia) {
            $mediaPath = null;
            $mediaType = $validated['media_type'] ?? 'none';
            $mediaUrl = $validated['media_url'] ?? null;
        } elseif (! $announcement->media_path) {
            $mediaType = $validated['media_type'] ?? $mediaType;
            $mediaUrl = array_key_exists('media_url', $validated)
                ? ($validated['media_url'] ?: null)
                : $mediaUrl;
        }

        if (! $mediaPath && ! $mediaUrl) {
            $mediaType = 'none';
        }

        $this->ensureMediaOnlyHasUsableMedia($displayStyle, $mediaType, $mediaUrl, $mediaPath);

        if ($displayStyle === 'media_only') {
            $backgroundColor = null;
        }

        if ($mediaPath === '__pending_upload__') {
            $mediaPath = $request->file('media_file')->store('platform-announcements', 'public');
        }

        $announcement->update([
            'title' => $content['title'],
            'body' => $content['body'],
            'translations' => $content['translations'],
            'status' => $validated['status'],
            'audience' => $validated['audience'],
            'placement' => $placement,
            'display_style' => $displayStyle,
            'background_color' => $backgroundColor,
            'new_tenant_days' => $validated['new_tenant_days'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl,
            'media_path' => $mediaPath,
            'link_label' => $content['link_label'],
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

        if ($originalMediaPath && $originalMediaPath !== $mediaPath) {
            Storage::disk('public')->delete($originalMediaPath);
        }

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

    private function ensureMediaOnlyHasUsableMedia(
        string $displayStyle,
        ?string $mediaType,
        ?string $mediaUrl,
        ?string $mediaPath,
    ): void {
        if ($displayStyle !== 'media_only' || PlatformAnnouncement::hasUsableMedia($mediaType, $mediaUrl, $mediaPath)) {
            return;
        }

        throw ValidationException::withMessages([
            'display_style' => __('ui.announcements.media_only_requires_media'),
        ]);
    }
}
