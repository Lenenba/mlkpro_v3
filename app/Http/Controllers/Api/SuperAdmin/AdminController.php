<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::ADMINS_MANAGE);

        $adminRoleId = Role::query()->where('name', 'admin')->value('id');
        $query = User::query()->where('role_id', $adminRoleId)->with('platformAdmin')->orderBy('name');
        $users = $query->get();

        $admins = $users->map(function (User $user) {
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

        $stats = [
            'total' => $admins->count(),
            'active' => $admins->filter(fn (array $admin) => (bool) ($admin['platform']['is_active'] ?? false))->count(),
            'inactive' => $admins->filter(fn (array $admin) => ! ($admin['platform']['is_active'] ?? false))->count(),
            'require_2fa' => $admins->filter(fn (array $admin) => (bool) ($admin['platform']['require_2fa'] ?? false))->count(),
        ];

        return $this->jsonResponse([
            'admins' => $admins->values(),
            'roles' => PlatformPermissions::roles(),
            'permissions' => PlatformPermissions::labels(),
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::ADMINS_MANAGE);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'role' => ['required', 'string', Rule::in(PlatformPermissions::roles())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PlatformPermissions::all())],
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

        $platformAdmin = PlatformAdmin::create([
            'user_id' => $user->id,
            'role' => $validated['role'],
            'permissions' => array_values($permissions),
            'is_active' => true,
            'require_2fa' => (bool) ($validated['require_2fa'] ?? false),
        ]);

        $this->logAudit($request, 'platform_admin.created', $user, [
            'role' => $validated['role'],
        ]);

        return $this->jsonResponse([
            'admin' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'platform' => [
                    'role' => $platformAdmin->role,
                    'permissions' => $platformAdmin->permissions ?? [],
                    'is_active' => $platformAdmin->is_active,
                    'require_2fa' => $platformAdmin->require_2fa,
                ],
            ],
            'temporary_password' => $tempPassword,
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, User $admin)
    {
        $this->authorizePermission($request, PlatformPermissions::ADMINS_MANAGE);

        $adminRoleId = Role::query()->where('name', 'admin')->value('id');
        if ($admin->role_id !== $adminRoleId) {
            abort(404);
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(PlatformPermissions::roles())],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PlatformPermissions::all())],
            'is_active' => 'nullable|boolean',
            'require_2fa' => 'nullable|boolean',
        ]);

        $platformAdmin = $admin->platformAdmin;
        if (!$platformAdmin) {
            $platformAdmin = PlatformAdmin::create([
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
            return $this->jsonResponse(
                ['message' => 'You cannot deactivate your own account.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
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

        return $this->jsonResponse([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'platform' => [
                    'role' => $platformAdmin->role,
                    'permissions' => $platformAdmin->permissions ?? [],
                    'is_active' => $platformAdmin->is_active,
                    'require_2fa' => $platformAdmin->require_2fa,
                ],
            ],
        ]);
    }
}
