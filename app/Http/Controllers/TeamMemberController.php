<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Services\CompanyFeatureService;
use App\Services\UsageLimitService;
use App\Support\NotificationDispatcher;
use App\Utils\FileHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    private const AVAILABLE_PERMISSIONS = [
        ['id' => 'jobs.view', 'name' => 'View assigned jobs'],
        ['id' => 'jobs.edit', 'name' => 'Edit assigned jobs'],
        ['id' => 'tasks.view', 'name' => 'View tasks'],
        ['id' => 'tasks.create', 'name' => 'Create tasks'],
        ['id' => 'tasks.edit', 'name' => 'Edit tasks'],
        ['id' => 'tasks.delete', 'name' => 'Delete tasks'],
        ['id' => 'quotes.view', 'name' => 'View quotes'],
        ['id' => 'quotes.create', 'name' => 'Create quotes'],
        ['id' => 'quotes.edit', 'name' => 'Edit quotes'],
        ['id' => 'quotes.send', 'name' => 'Send quotes'],
        ['id' => 'reservations.view', 'name' => 'View reservations'],
        ['id' => 'reservations.queue', 'name' => 'Operate queue actions'],
        ['id' => 'reservations.manage', 'name' => 'Manage reservations settings and updates'],
        ['id' => 'sales.manage', 'name' => 'Manage sales'],
        ['id' => 'sales.pos', 'name' => 'POS access only'],
        ['id' => 'campaigns.view', 'name' => 'View campaigns'],
        ['id' => 'campaigns.manage', 'name' => 'Create and edit campaigns'],
        ['id' => 'campaigns.send', 'name' => 'Send campaigns'],
        ['id' => 'expenses.view', 'name' => 'View expenses'],
        ['id' => 'expenses.create', 'name' => 'Create expenses'],
        ['id' => 'expenses.edit', 'name' => 'Edit submitted expenses'],
        ['id' => 'expenses.approve', 'name' => 'Approve finance requests within base threshold'],
        ['id' => 'expenses.approve_high', 'name' => 'Approve finance requests above the base threshold'],
        ['id' => 'expenses.pay', 'name' => 'Mark expenses as due, paid, or reimbursed'],
        ['id' => 'invoices.view', 'name' => 'View invoices'],
        ['id' => 'invoices.create', 'name' => 'Create invoices'],
        ['id' => 'invoices.edit', 'name' => 'Edit invoices'],
        ['id' => 'invoices.approve', 'name' => 'Approve invoices within base threshold'],
        ['id' => 'invoices.approve_high', 'name' => 'Approve invoices above the base threshold'],
    ];

    private const PERMISSION_FEATURE_MAP = [
        'jobs.view' => 'jobs',
        'jobs.edit' => 'jobs',
        'tasks.view' => 'tasks',
        'tasks.create' => 'tasks',
        'tasks.edit' => 'tasks',
        'tasks.delete' => 'tasks',
        'quotes.view' => 'quotes',
        'quotes.create' => 'quotes',
        'quotes.edit' => 'quotes',
        'quotes.send' => 'quotes',
        'reservations.view' => 'reservations',
        'reservations.queue' => 'reservations',
        'reservations.manage' => 'reservations',
        'sales.manage' => 'sales',
        'sales.pos' => 'sales',
        'campaigns.view' => 'campaigns',
        'campaigns.manage' => 'campaigns',
        'campaigns.send' => 'campaigns',
        'expenses.view' => 'expenses',
        'expenses.create' => 'expenses',
        'expenses.edit' => 'expenses',
        'expenses.approve' => 'expenses',
        'expenses.approve_high' => 'expenses',
        'expenses.pay' => 'expenses',
        'invoices.view' => 'invoices',
        'invoices.create' => 'invoices',
        'invoices.edit' => 'invoices',
        'invoices.approve' => 'invoices',
        'invoices.approve_high' => 'invoices',
    ];

    public function index(Request $request)
    {
        $user = Auth::user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }

        $filters = $request->only([
            'search',
        ]);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);

        $search = trim((string) ($filters['search'] ?? ''));

        $baseQuery = TeamMember::query()
            ->forAccount($user->id)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($memberQuery) use ($search) {
                    $memberQuery
                        ->whereHas('user', function ($userQuery) use ($search) {
                            $userQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhere('role', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            });

        $teamMembers = (clone $baseQuery)
            ->with('user')
            ->orderBy('created_at')
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $statsMembers = (clone $baseQuery)->get([
            'id',
            'role',
            'is_active',
        ]);

        $availablePermissions = $this->availablePermissionsForAccount($user);

        return $this->inertiaOrJson('Team/Index', [
            'teamMembers' => $teamMembers,
            'filters' => $filters,
            'availablePermissions' => $availablePermissions,
            'stats' => [
                'total' => $statsMembers->count(),
                'active' => $statsMembers->where('is_active', true)->count(),
                'admins' => $statsMembers->where('role', 'admin')->count(),
                'members' => $statsMembers->whereIn('role', ['member', 'seller', 'sales_manager'])->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'team_members');

        $allowedPermissions = $this->allowedPermissionIdsForAccount($user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'role' => 'required|string|in:admin,member,seller,sales_manager',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in($allowedPermissions)],
            'planning_rules' => 'nullable|array',
            'planning_rules.break_minutes' => 'nullable|integer|min:0|max:240',
            'planning_rules.min_hours_day' => 'nullable|numeric|min:0|max:24',
            'planning_rules.max_hours_day' => 'nullable|numeric|min:0|max:24',
            'planning_rules.max_hours_week' => 'nullable|numeric|min:0|max:168',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
            'avatar_icon' => [
                'nullable',
                'string',
                'max:255',
                Rule::in(config('icon_presets.avatar_icons', [])),
            ],
        ]);

        $roleId = Role::where('name', 'employee')->value('id');
        if (! $roleId) {
            $roleId = Role::create([
                'name' => 'employee',
                'description' => 'Employee role',
            ])->id;
        }

        $defaultAvatar = config('icon_presets.defaults.avatar');
        $profilePicture = $defaultAvatar;
        if ($request->hasFile('profile_picture')) {
            $profilePicture = FileHandler::handleImageUpload('team', $request, 'profile_picture', $defaultAvatar);
        } elseif (! empty($validated['avatar_icon'])) {
            $profilePicture = $validated['avatar_icon'];
        }

        $memberUser = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(32)),
            'role_id' => $roleId,
            'email_verified_at' => now(),
            'must_change_password' => true,
            'profile_picture' => $profilePicture,
        ]);

        $permissions = array_values($validated['permissions'] ?? []);
        if (! $permissions) {
            $permissions = $this->defaultPermissionsForRole($validated['role'], $allowedPermissions);
        }

        $planningRules = $this->normalizePlanningRules($validated['planning_rules'] ?? null);

        $teamMember = TeamMember::create([
            'account_id' => $user->id,
            'user_id' => $memberUser->id,
            'role' => $validated['role'],
            'title' => $validated['title'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'permissions' => $permissions,
            'planning_rules' => $planningRules,
            'is_active' => true,
        ]);

        $token = Password::broker()->createToken($memberUser);
        $inviteQueued = NotificationDispatcher::send($memberUser, new InviteUserNotification(
            $token,
            $user->company_name ?: config('app.name'),
            $user->company_logo_url,
            'team'
        ), [
            'team_member_id' => $teamMember->id,
        ]);

        if ($this->shouldReturnJson($request)) {
            if (! $inviteQueued) {
                return response()->json([
                    'message' => 'Team member created, but the invite email could not be sent.',
                    'warning' => true,
                    'team_member' => $teamMember->load('user'),
                ], 201);
            }

            return response()->json([
                'message' => 'Team member created. Invite sent by email.',
                'team_member' => $teamMember->load('user'),
            ], 201);
        }

        if (! $inviteQueued) {
            return redirect()->back()->with('warning', 'Team member created, but the invite email could not be sent.');
        }

        return redirect()->back()->with('success', 'Team member created. Invite sent by email.');
    }

    public function update(Request $request, TeamMember $teamMember)
    {
        $user = Auth::user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }

        if ($teamMember->account_id !== $user->id) {
            abort(404);
        }

        $allowedPermissions = $this->allowedPermissionIdsForAccount($user);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|string|lowercase|email|max:255|unique:users,email,'.$teamMember->user_id,
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|string|in:admin,member,seller,sales_manager',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in($allowedPermissions)],
            'planning_rules' => 'nullable|array',
            'planning_rules.break_minutes' => 'nullable|integer|min:0|max:240',
            'planning_rules.min_hours_day' => 'nullable|numeric|min:0|max:24',
            'planning_rules.max_hours_day' => 'nullable|numeric|min:0|max:24',
            'planning_rules.max_hours_week' => 'nullable|numeric|min:0|max:168',
            'is_active' => 'nullable|boolean',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png,svg|max:2048',
            'avatar_icon' => [
                'nullable',
                'string',
                'max:255',
                Rule::in(config('icon_presets.avatar_icons', [])),
            ],
        ]);

        $memberUser = $teamMember->user;
        if (! $memberUser) {
            abort(404);
        }

        $userUpdates = [];
        if (array_key_exists('name', $validated)) {
            $userUpdates['name'] = $validated['name'];
        }
        if (array_key_exists('email', $validated)) {
            $userUpdates['email'] = $validated['email'];
        }
        if (! empty($validated['password'])) {
            $userUpdates['password'] = Hash::make($validated['password']);
        }
        $defaultAvatar = config('icon_presets.defaults.avatar');
        if ($request->hasFile('profile_picture')) {
            $userUpdates['profile_picture'] = FileHandler::handleImageUpload(
                'team',
                $request,
                'profile_picture',
                $defaultAvatar,
                $memberUser->profile_picture
            );
        } elseif (! empty($validated['avatar_icon'])) {
            if (
                $memberUser->profile_picture
                && $memberUser->profile_picture !== $validated['avatar_icon']
                && ! str_starts_with($memberUser->profile_picture, '/')
                && ! str_starts_with($memberUser->profile_picture, 'http://')
                && ! str_starts_with($memberUser->profile_picture, 'https://')
                && $memberUser->profile_picture !== $defaultAvatar
            ) {
                FileHandler::deleteFile($memberUser->profile_picture, $defaultAvatar);
            }
            $userUpdates['profile_picture'] = $validated['avatar_icon'];
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
        if (array_key_exists('planning_rules', $validated)) {
            $teamMemberUpdates['planning_rules'] = $this->normalizePlanningRules($validated['planning_rules']);
        }
        if ($teamMemberUpdates) {
            $teamMember->update($teamMemberUpdates);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Team member updated.',
                'team_member' => $teamMember->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Team member updated.');
    }

    public function destroy(TeamMember $teamMember)
    {
        $user = Auth::user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }

        if ($teamMember->account_id !== $user->id) {
            abort(404);
        }

        $teamMember->works()->detach();
        $teamMember->update(['is_active' => false]);

        if ($this->shouldReturnJson()) {
            return response()->json([
                'message' => 'Team member deactivated.',
                'team_member' => $teamMember->fresh(),
            ]);
        }

        return redirect()->back()->with('success', 'Team member deactivated.');
    }

    private function availablePermissionsForAccount(User $accountOwner): array
    {
        $featureService = app(CompanyFeatureService::class);

        return array_values(array_filter(
            self::AVAILABLE_PERMISSIONS,
            function (array $permission) use ($accountOwner, $featureService): bool {
                $permissionId = (string) ($permission['id'] ?? '');
                if ($permissionId === '') {
                    return false;
                }

                $feature = self::PERMISSION_FEATURE_MAP[$permissionId] ?? null;
                if (! $feature) {
                    return true;
                }

                return $featureService->hasFeature($accountOwner, $feature);
            }
        ));
    }

    private function allowedPermissionIdsForAccount(User $accountOwner): array
    {
        return collect($this->availablePermissionsForAccount($accountOwner))
            ->pluck('id')
            ->values()
            ->all();
    }

    private function defaultPermissionsForRole(string $role, ?array $allowedPermissions = null): array
    {
        $defaults = match ($role) {
            'admin' => [
                'jobs.view',
                'jobs.edit',
                'tasks.view',
                'tasks.create',
                'tasks.edit',
                'tasks.delete',
                'quotes.view',
                'quotes.create',
                'quotes.edit',
                'quotes.send',
                'reservations.view',
                'reservations.queue',
                'reservations.manage',
                'sales.manage',
                'campaigns.view',
                'campaigns.manage',
                'expenses.view',
                'expenses.create',
                'expenses.edit',
                'expenses.approve',
                'expenses.pay',
                'invoices.view',
                'invoices.create',
                'invoices.edit',
                'invoices.approve',
            ],
            'seller' => [
                'sales.pos',
            ],
            'sales_manager' => [
                'sales.manage',
                'reservations.view',
                'reservations.queue',
                'reservations.manage',
                'campaigns.view',
                'campaigns.manage',
                'expenses.view',
                'expenses.create',
                'expenses.edit',
                'expenses.approve_high',
                'expenses.pay',
                'invoices.view',
                'invoices.create',
                'invoices.edit',
                'invoices.approve_high',
            ],
            default => [
                'jobs.view',
                'tasks.view',
                'tasks.edit',
                'reservations.view',
                'reservations.queue',
                'expenses.view',
                'expenses.create',
            ],
        };

        if (! is_array($allowedPermissions)) {
            return $defaults;
        }

        return array_values(array_intersect($defaults, $allowedPermissions));
    }

    private function normalizePlanningRules(?array $rules): ?array
    {
        if (! $rules) {
            return null;
        }

        $normalized = [];
        if (array_key_exists('break_minutes', $rules) && $rules['break_minutes'] !== null && $rules['break_minutes'] !== '') {
            $normalized['break_minutes'] = (int) $rules['break_minutes'];
        }
        if (array_key_exists('min_hours_day', $rules) && $rules['min_hours_day'] !== null && $rules['min_hours_day'] !== '') {
            $normalized['min_hours_day'] = (float) $rules['min_hours_day'];
        }
        if (array_key_exists('max_hours_day', $rules) && $rules['max_hours_day'] !== null && $rules['max_hours_day'] !== '') {
            $normalized['max_hours_day'] = (float) $rules['max_hours_day'];
        }
        if (array_key_exists('max_hours_week', $rules) && $rules['max_hours_week'] !== null && $rules['max_hours_week'] !== '') {
            $normalized['max_hours_week'] = (float) $rules['max_hours_week'];
        }

        if (isset($normalized['min_hours_day'], $normalized['max_hours_day'])
            && $normalized['max_hours_day'] < $normalized['min_hours_day']) {
            throw ValidationException::withMessages([
                'planning_rules.max_hours_day' => ['La limite max doit etre superieure a la limite min.'],
            ]);
        }

        return $normalized ?: null;
    }
}
