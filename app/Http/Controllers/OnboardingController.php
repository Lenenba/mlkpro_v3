<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if (!$user->isAccountOwner()) {
            return Inertia::render('Onboarding/PendingOwner');
        }

        $companyLogo = null;
        if ($user->company_logo) {
            $path = $user->company_logo;
            $companyLogo = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : Storage::disk('public')->url($path);
        }

        return Inertia::render('Onboarding/Index', [
            'preset' => [
                'company_name' => $user->company_name,
                'company_logo' => $companyLogo,
                'company_description' => $user->company_description,
                'company_country' => $user->company_country,
                'company_province' => $user->company_province,
                'company_city' => $user->company_city,
                'company_type' => $user->company_type,
                'onboarding_completed_at' => $user->onboarding_completed_at,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $creator = $request->user();
        if (!$creator) {
            abort(401);
        }

        if (!$creator->isAccountOwner()) {
            return redirect()->route('dashboard')->with('error', 'Only the account owner can complete onboarding.');
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|max:2048',
            'company_description' => 'nullable|string|max:2000',
            'company_country' => 'nullable|string|max:255',
            'company_province' => 'nullable|string|max:255',
            'company_city' => 'nullable|string|max:255',
            'company_type' => 'required|string|in:services,products',
            'is_owner' => 'required|boolean',

            'owner_name' => 'nullable|string|max:255|required_if:is_owner,0',
            'owner_email' => 'nullable|string|lowercase|email|max:255|required_if:is_owner,0|unique:users,email',

            'invites' => 'nullable|array|max:20',
            'invites.*.name' => 'required|string|max:255',
            'invites.*.email' => 'required|string|lowercase|email|max:255|distinct|unique:users,email',
            'invites.*.role' => 'required|string|in:admin,member',
        ]);

        $clientRoleId = Role::query()->firstOrCreate(
            ['name' => 'client'],
            ['description' => 'Default client role']
        )->id;

        $employeeRoleId = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        )->id;

        $accountOwner = $creator;
        $ownerPassword = null;

        if (!$validated['is_owner']) {
            $ownerPassword = Str::random(14);
            $accountOwner = User::create([
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'password' => Hash::make($ownerPassword),
                'role_id' => $clientRoleId,
                'email_verified_at' => now(),
            ]);

            TeamMember::updateOrCreate(
                [
                    'account_id' => $accountOwner->id,
                    'user_id' => $creator->id,
                ],
                [
                    'role' => 'admin',
                    'permissions' => $this->defaultPermissionsForRole('admin'),
                    'is_active' => true,
                ]
            );
        }

        $companyLogoPath = $accountOwner->company_logo;
        if ($request->hasFile('company_logo')) {
            $companyLogoPath = $request->file('company_logo')->store('company/logos', 'public');
        }

        $accountOwner->update([
            'company_name' => $validated['company_name'],
            'company_logo' => $companyLogoPath,
            'company_description' => $validated['company_description'] ?? null,
            'company_country' => $validated['company_country'] ?? null,
            'company_province' => $validated['company_province'] ?? null,
            'company_city' => $validated['company_city'] ?? null,
            'company_type' => $validated['company_type'],
            'onboarding_completed_at' => now(),
        ]);

        $invitePasswords = [];
        foreach (($validated['invites'] ?? []) as $invite) {
            $plainPassword = Str::random(14);
            $memberUser = User::create([
                'name' => $invite['name'],
                'email' => $invite['email'],
                'password' => Hash::make($plainPassword),
                'role_id' => $employeeRoleId,
                'email_verified_at' => now(),
            ]);

            TeamMember::create([
                'account_id' => $accountOwner->id,
                'user_id' => $memberUser->id,
                'role' => $invite['role'],
                'permissions' => $this->defaultPermissionsForRole($invite['role']),
                'is_active' => true,
            ]);

            $invitePasswords[] = $invite['email'] . '=' . $plainPassword;
        }

        $messageParts = ['Onboarding completed.'];
        if ($ownerPassword) {
            $messageParts[] = 'Owner login: ' . $accountOwner->email . ' / ' . $ownerPassword;
        }
        if ($invitePasswords) {
            $messageParts[] = 'Team passwords: ' . implode(', ', $invitePasswords);
        }

        return redirect()->route('dashboard')->with('success', implode(' ', $messageParts));
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
            'member' => [
                'jobs.view',
                'tasks.view',
                'tasks.edit',
            ],
            default => [
                'jobs.view',
            ],
        };
    }
}
