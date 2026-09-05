<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CompanyRole;
use App\Models\Permission;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Rbac\CompanyPermissionAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    public function __construct(private readonly CompanyPermissionAvailability $permissionAvailability) {}

    public function edit(Request $request)
    {
        $accountOwner = $this->accountOwner($request);
        $availablePermissionSlugs = $this->permissionAvailability->availableSlugs($accountOwner);

        return $this->inertiaOrJson('Settings/RolesPermissions', [
            'roles' => $this->rolePayload($accountOwner->id, $availablePermissionSlugs),
            'permissions' => $this->permissionPayload($availablePermissionSlugs),
            'teamMembers' => $this->teamMemberPayload($accountOwner->id),
        ]);
    }

    public function store(Request $request)
    {
        $accountOwner = $this->accountOwner($request);
        $accountId = (int) $accountOwner->id;
        $availablePermissionSlugs = $this->permissionAvailability->availableSlugs($accountOwner);
        $validated = $this->validatedRoleInput($request, $availablePermissionSlugs);

        $role = CompanyRole::query()->create([
            'company_id' => $accountId,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($accountId, $validated['name']),
            'description' => $validated['description'] ?? null,
            'is_system' => false,
            'is_default' => false,
            'is_editable' => true,
            'is_deletable' => true,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $role->permissions()->sync($this->permissionIds(
            $validated['permissions'] ?? [],
            $availablePermissionSlugs,
        ));

        return $this->roleMutationResponse($request, 'Role created.', $role, 201);
    }

    public function update(Request $request, CompanyRole $companyRole)
    {
        $accountOwner = $this->accountOwner($request);
        $accountId = (int) $accountOwner->id;
        $this->ensureMutableCompanyRole($companyRole, $accountId);

        $availablePermissionSlugs = $this->permissionAvailability->availableSlugs($accountOwner);
        $validated = $this->validatedRoleInput($request, $availablePermissionSlugs);

        $companyRole->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);
        $companyRole->permissions()->sync($this->permissionIds(
            $validated['permissions'] ?? [],
            $availablePermissionSlugs,
        ));

        return $this->roleMutationResponse($request, 'Role updated.', $companyRole);
    }

    public function duplicate(Request $request, CompanyRole $companyRole)
    {
        $accountOwner = $this->accountOwner($request);
        $accountId = (int) $accountOwner->id;
        $this->ensureReadableRole($companyRole, $accountId);
        $availablePermissionSlugs = $this->permissionAvailability->availableSlugs($accountOwner);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $source = $companyRole->loadMissing('permissions');
        $name = trim((string) ($validated['name'] ?? '')) ?: $source->name.' copy';
        $copy = CompanyRole::query()->create([
            'company_id' => $accountId,
            'name' => $name,
            'slug' => $this->uniqueSlug($accountId, $name),
            'description' => $source->description,
            'is_system' => false,
            'is_default' => false,
            'is_editable' => true,
            'is_deletable' => true,
            'is_active' => true,
        ]);
        $copy->permissions()->sync($this->permissionIds(
            $source->permissions->pluck('slug')->all(),
            $availablePermissionSlugs,
        ));

        return $this->roleMutationResponse($request, 'Role duplicated.', $copy, 201);
    }

    public function toggle(Request $request, CompanyRole $companyRole)
    {
        $accountId = (int) $request->user()->accountOwnerId();
        $this->ensureMutableCompanyRole($companyRole, $accountId);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $companyRole->update([
            'is_active' => (bool) $validated['is_active'],
        ]);

        return $this->roleMutationResponse($request, 'Role status updated.', $companyRole);
    }

    public function destroy(Request $request, CompanyRole $companyRole)
    {
        $accountId = (int) $request->user()->accountOwnerId();
        $this->ensureMutableCompanyRole($companyRole, $accountId);

        if (! $companyRole->isDeletable()) {
            abort(403, 'This role is protected.');
        }

        if ($companyRole->teamMembers()->exists()) {
            return $this->roleErrorResponse($request, 'Role is assigned to team members and cannot be deleted.', 422);
        }

        $companyRole->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Role deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'Role deleted.');
    }

    /**
     * @param  array<int, string>  $availablePermissionSlugs
     * @return array<int, array<string, mixed>>
     */
    private function rolePayload(int $accountId, array $availablePermissionSlugs): array
    {
        return CompanyRole::query()
            ->with('permissions:id,group,name,slug')
            ->withCount([
                'teamMembers as tenant_members_count' => fn (Builder $query): Builder => $query
                    ->where('account_id', $accountId),
            ])
            ->availableForCompany($accountId)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyRole $role): array => $this->formatRole($role, $availablePermissionSlugs))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $availablePermissionSlugs
     * @return array<int, array<string, mixed>>
     */
    private function permissionPayload(array $availablePermissionSlugs): array
    {
        return Permission::query()
            ->whereIn('slug', $availablePermissionSlugs)
            ->orderBy('group')
            ->orderBy('name')
            ->get(['id', 'group', 'name', 'slug', 'description'])
            ->groupBy('group')
            ->map(fn ($items, string $group): array => [
                'group' => $group,
                'label' => $this->permissionGroupLabel($group),
                'permissions' => $items->map(fn (Permission $permission): array => [
                    'id' => $permission->id,
                    'slug' => $permission->slug,
                    'name' => $permission->name,
                    'description' => $permission->description,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function permissionGroupLabel(string $group): string
    {
        return match ($group) {
            'social' => 'Pulse',
            default => Str::headline($group),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamMemberPayload(int $accountId): array
    {
        return TeamMember::query()
            ->forAccount($accountId)
            ->with(['user:id,name,email', 'companyRole:id,name,slug'])
            ->orderBy('created_at')
            ->get(['id', 'account_id', 'user_id', 'company_role_id', 'role', 'title', 'is_active'])
            ->map(fn (TeamMember $member): array => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'legacy_role' => $member->role,
                'title' => $member->title,
                'is_active' => $member->is_active,
                'company_role' => $member->companyRole ? [
                    'id' => $member->companyRole->id,
                    'name' => $member->companyRole->name,
                    'slug' => $member->companyRole->slug,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $availablePermissionSlugs
     * @return array{name: string, description?: string|null, is_active?: bool, permissions?: array<int, string>}
     */
    private function validatedRoleInput(Request $request, array $availablePermissionSlugs): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($availablePermissionSlugs)],
        ]);
    }

    /**
     * @param  array<int, string>  $slugs
     * @param  array<int, string>  $availablePermissionSlugs
     * @return array<int, int>
     */
    private function permissionIds(array $slugs, array $availablePermissionSlugs): array
    {
        return Permission::query()
            ->whereIn('slug', array_values(array_intersect($slugs, $availablePermissionSlugs)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function uniqueSlug(int $accountId, string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (
            CompanyRole::query()
                ->where('company_id', $accountId)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function ensureReadableRole(CompanyRole $role, int $accountId): void
    {
        if ($role->isAvailableForCompany($accountId)) {
            return;
        }

        abort(404);
    }

    private function ensureMutableCompanyRole(CompanyRole $role, int $accountId): void
    {
        $this->ensureReadableRole($role, $accountId);

        if ((int) $role->company_id !== $accountId || ! $role->isEditable()) {
            abort(403, 'Duplicate this system role before customizing it.');
        }
    }

    /**
     * @param  array<int, string>  $availablePermissionSlugs
     * @return array<string, mixed>
     */
    private function formatRole(CompanyRole $role, array $availablePermissionSlugs): array
    {
        return [
            'id' => $role->id,
            'company_id' => $role->company_id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_default' => $role->is_default,
            'is_editable' => $role->is_editable && $role->company_id !== null,
            'is_deletable' => $role->is_deletable && $role->company_id !== null,
            'is_active' => $role->is_active,
            'members_count' => (int) $role->tenant_members_count,
            'permissions' => $role->permissions
                ->whereIn('slug', $availablePermissionSlugs)
                ->pluck('slug')
                ->values()
                ->all(),
        ];
    }

    private function roleMutationResponse(Request $request, string $message, CompanyRole $role, int $status = 200)
    {
        if ($this->shouldReturnJson($request)) {
            $accountId = (int) $request->user()->accountOwnerId();
            $accountOwner = User::query()->findOrFail($accountId);
            $availablePermissionSlugs = $this->permissionAvailability->availableSlugs($accountOwner);
            $freshRole = $role->fresh('permissions') ?? $role->load('permissions');
            $freshRole->loadCount([
                'teamMembers as tenant_members_count' => fn (Builder $query): Builder => $query
                    ->where('account_id', $accountId),
            ]);

            return response()->json([
                'message' => $message,
                'role' => $this->formatRole($freshRole, $availablePermissionSlugs),
            ], $status);
        }

        return redirect()->back()->with('success', $message);
    }

    private function roleErrorResponse(Request $request, string $message, int $status)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
            ], $status);
        }

        return redirect()->back()->withErrors([
            'role' => $message,
        ]);
    }

    private function accountOwner(Request $request): User
    {
        return User::query()->findOrFail((int) $request->user()->accountOwnerId());
    }
}
