<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class TeamMemberController extends Controller
{
    private const AVAILABLE_PERMISSIONS = [
        ['id' => 'jobs.view', 'name' => 'View assigned jobs'],
        ['id' => 'jobs.edit', 'name' => 'Edit assigned jobs'],
        ['id' => 'tasks.view', 'name' => 'View tasks'],
        ['id' => 'tasks.create', 'name' => 'Create tasks'],
        ['id' => 'tasks.edit', 'name' => 'Edit tasks'],
        ['id' => 'tasks.delete', 'name' => 'Delete tasks'],
    ];

    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $teamMembers = TeamMember::query()
            ->forAccount($user->id)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        return inertia('Team/Index', [
            'teamMembers' => $teamMembers,
            'availablePermissions' => self::AVAILABLE_PERMISSIONS,
            'stats' => [
                'total' => $teamMembers->count(),
                'active' => $teamMembers->where('is_active', true)->count(),
                'admins' => $teamMembers->where('role', 'admin')->count(),
                'members' => $teamMembers->where('role', 'member')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'team_members');

        $allowedPermissions = collect(self::AVAILABLE_PERMISSIONS)->pluck('id')->all();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'role' => 'required|string|in:admin,member',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', 'in:' . implode(',', $allowedPermissions)],
        ]);

        $roleId = Role::where('name', 'employee')->value('id');
        if (!$roleId) {
            $roleId = Role::create([
                'name' => 'employee',
                'description' => 'Employee role',
            ])->id;
        }

        $memberUser = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(32)),
            'role_id' => $roleId,
            'email_verified_at' => now(),
            'must_change_password' => true,
        ]);

        $permissions = array_values($validated['permissions'] ?? []);
        if (!$permissions) {
            $permissions = $this->defaultPermissionsForRole($validated['role']);
        }

        TeamMember::create([
            'account_id' => $user->id,
            'user_id' => $memberUser->id,
            'role' => $validated['role'],
            'title' => $validated['title'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'permissions' => $permissions,
            'is_active' => true,
        ]);

        $token = Password::broker()->createToken($memberUser);
        $memberUser->notify(new InviteUserNotification(
            $token,
            $user->company_name ?: config('app.name'),
            $user->company_logo_url,
            'team'
        ));

        return redirect()->back()->with('success', 'Team member created. Invite sent by email.');
    }

    public function update(Request $request, TeamMember $teamMember)
    {
        $user = Auth::user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        if ($teamMember->account_id !== $user->id) {
            abort(404);
        }

        $allowedPermissions = collect(self::AVAILABLE_PERMISSIONS)->pluck('id')->all();

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|string|lowercase|email|max:255|unique:users,email,' . $teamMember->user_id,
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|string|in:admin,member',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', 'in:' . implode(',', $allowedPermissions)],
            'is_active' => 'nullable|boolean',
        ]);

        $memberUser = $teamMember->user;
        if (!$memberUser) {
            abort(404);
        }

        $userUpdates = [];
        if (array_key_exists('name', $validated)) {
            $userUpdates['name'] = $validated['name'];
        }
        if (array_key_exists('email', $validated)) {
            $userUpdates['email'] = $validated['email'];
        }
        if (!empty($validated['password'])) {
            $userUpdates['password'] = Hash::make($validated['password']);
        }
        if ($userUpdates) {
            $memberUser->update($userUpdates);
        }

        $teamMemberUpdates = [];
        foreach (['role', 'title', 'phone', 'permissions', 'is_active'] as $field) {
            if (array_key_exists($field, $validated)) {
                $teamMemberUpdates[$field] = $validated[$field];
            }
        }
        if ($teamMemberUpdates) {
            $teamMember->update($teamMemberUpdates);
        }

        return redirect()->back()->with('success', 'Team member updated.');
    }

    public function destroy(TeamMember $teamMember)
    {
        $user = Auth::user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        if ($teamMember->account_id !== $user->id) {
            abort(404);
        }

        $teamMember->works()->detach();
        $teamMember->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Team member deactivated.');
    }

    private function defaultPermissionsForRole(string $role): array
    {
        return match ($role) {
            'admin' => [
                'jobs.view',
                'jobs.edit',
                'tasks.view',
                'tasks.create',
                'tasks.edit',
                'tasks.delete',
            ],
            default => [
                'jobs.view',
                'tasks.view',
                'tasks.edit',
            ],
        };
    }
}
