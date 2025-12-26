<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ADMINS_MANAGE);

        $adminRoleId = Role::query()->where('name', 'admin')->value('id');

        $admins = User::query()
            ->where('role_id', $adminRoleId)
            ->with('platformAdmin')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'created_at' => $user->created_at,
                    'platform' => $user->platformAdmin ? [
                        'role' => $user->platformAdmin->role,
                        'permissions' => $user->platformAdmin->permissions ?? [],
                        'is_active' => (bool) $user->platformAdmin->is_active,
                        'require_2fa' => (bool) $user->platformAdmin->require_2fa,
                    ] : null,
                ];
            });

        $activeCount = $admins->filter(fn(array $admin) => (bool) ($admin['platform']['is_active'] ?? false))->count();
        $inactiveCount = $admins->filter(fn(array $admin) => !($admin['platform']['is_active'] ?? false))->count();
        $twoFactorCount = $admins->filter(fn(array $admin) => (bool) ($admin['platform']['require_2fa'] ?? false))->count();

        return Inertia::render('SuperAdmin/Admins/Index', [
            'admins' => $admins,
            'roles' => PlatformPermissions::roles(),
            'permissions' => PlatformPermissions::labels(),
            'stats' => [
                'total' => $admins->count(),
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'require_2fa' => $twoFactorCount,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::ADMINS_MANAGE);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => 'required|string|in:' . implode(',', PlatformPermissions::roles()),
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:' . implode(',', PlatformPermissions::all()),
            'require_2fa' => 'nullable|boolean',
        ]);

        $adminRoleId = Role::query()->where('name', 'admin')->value('id');

        $tempPassword = Str::random(12);
        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $tempPassword,
            'role_id' => $adminRoleId,
            'must_change_password' => true,
        ]);

        $permissions = $validated['permissions'] ?? PlatformPermissions::defaultForRole($validated['role']);

        PlatformAdmin::query()->create([
            'user_id' => $user->id,
            'role' => $validated['role'],
            'permissions' => array_values($permissions),
            'is_active' => true,
            'require_2fa' => (bool) ($validated['require_2fa'] ?? false),
        ]);

        $this->logAudit($request, 'platform_admin.created', $user, [
            'role' => $validated['role'],
        ]);

        return redirect()->back()->with(
            'success',
            'Platform admin created. Temporary password: ' . $tempPassword
        );
    }

    public function update(Request $request, User $admin): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::ADMINS_MANAGE);

        $adminRoleId = Role::query()->where('name', 'admin')->value('id');
        if ($admin->role_id !== $adminRoleId) {
            abort(404);
        }

        $validated = $request->validate([
            'role' => 'required|string|in:' . implode(',', PlatformPermissions::roles()),
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:' . implode(',', PlatformPermissions::all()),
            'is_active' => 'nullable|boolean',
            'require_2fa' => 'nullable|boolean',
        ]);

        $platformAdmin = $admin->platformAdmin;
        if (!$platformAdmin) {
            $platformAdmin = PlatformAdmin::query()->create([
                'user_id' => $admin->id,
                'role' => $validated['role'],
                'permissions' => PlatformPermissions::defaultForRole($validated['role']),
                'is_active' => true,
                'require_2fa' => false,
            ]);
        }

        $newActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : $platformAdmin->is_active;

        if ($request->user()?->id === $admin->id && !$newActive) {
            return redirect()->back()->with('error', 'You cannot deactivate your own account.');
        }

        $permissions = $validated['permissions'] ?? PlatformPermissions::defaultForRole($validated['role']);

        $platformAdmin->update([
            'role' => $validated['role'],
            'permissions' => array_values($permissions),
            'is_active' => $newActive,
            'require_2fa' => (bool) ($validated['require_2fa'] ?? $platformAdmin->require_2fa),
        ]);

        $this->logAudit($request, 'platform_admin.updated', $admin, [
            'role' => $validated['role'],
        ]);

        return redirect()->back()->with('success', 'Platform admin updated.');
    }
}
