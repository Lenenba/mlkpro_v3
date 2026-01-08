<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CompanyFeatureService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private function buildMeta(User $user): array
    {
        $ownerId = $user->accountOwnerId();

        if ($user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();

            if ($customer?->user_id) {
                $ownerId = $customer->user_id;
            }
        }

        $owner = $ownerId === $user->id
            ? $user
            : User::query()
                ->select(['id', 'company_name', 'company_type', 'company_logo', 'onboarding_completed_at'])
                ->find($ownerId);

        $features = $owner ? app(CompanyFeatureService::class)->resolveEffectiveFeatures($owner) : [];

        $teamMembership = null;
        if (!$user->isAccountOwner()) {
            $teamMembership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
        }

        $platformAdmin = null;
        if ($user->isPlatformAdmin()) {
            $platformAdmin = $user->relationLoaded('platformAdmin')
                ? $user->platformAdmin
                : $user->platformAdmin()->first();
        }

        return [
            'role_name' => $user->role?->name,
            'owner_id' => $ownerId,
            'is_owner' => $user->isAccountOwner(),
            'is_client' => $user->isClient(),
            'is_superadmin' => $user->isSuperadmin(),
            'is_platform_admin' => $user->isPlatformAdmin(),
            'company' => $owner ? [
                'name' => $owner->company_name,
                'type' => $owner->company_type,
                'onboarded' => (bool) $owner->onboarding_completed_at,
                'logo_url' => $owner->company_logo_url,
            ] : null,
            'features' => $features,
            'platform' => $platformAdmin ? [
                'role' => $platformAdmin->role,
                'permissions' => $platformAdmin->permissions ?? [],
                'is_active' => (bool) $platformAdmin->is_active,
            ] : null,
            'team' => $teamMembership ? [
                'role' => $teamMembership->role,
                'permissions' => $teamMembership->permissions ?? [],
            ] : null,
        ];
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($user->isSuspended()) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        $deviceName = $validated['device_name'] ?? '';
        if ($deviceName === '') {
            $deviceName = trim((string) $request->userAgent());
        }
        if ($deviceName === '') {
            $deviceName = 'mobile';
        }
        $deviceName = Str::limit($deviceName, 80, '');

        $user->loadMissing(['role', 'platformAdmin', 'teamMembership']);
        $token = $user->createToken($deviceName);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'user' => $user,
            'meta' => $this->buildMeta($user),
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'device_name' => 'nullable|string|max:255',
        ]);

        $roleId = Role::where('name', 'owner')->value('id');
        if (!$roleId) {
            $roleId = Role::create([
                'name' => 'owner',
                'description' => 'Account owner role',
            ])->id;
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id' => $roleId,
        ]);

        event(new Registered($user));

        $deviceName = $validated['device_name'] ?? '';
        if ($deviceName === '') {
            $deviceName = trim((string) $request->userAgent());
        }
        if ($deviceName === '') {
            $deviceName = 'mobile';
        }
        $deviceName = Str::limit($deviceName, 80, '');

        $user->loadMissing(['role', 'platformAdmin', 'teamMembership']);
        $token = $user->createToken($deviceName);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'user' => $user,
            'meta' => $this->buildMeta($user),
        ], 201);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink([
            'email' => $validated['email'],
        ]);

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => 'Password reset link sent.',
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function ($user) use ($validated) {
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Password reset successfully.',
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        if (method_exists($user, 'hasVerifiedEmail') && $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        if (method_exists($user, 'sendEmailVerificationNotification')) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Verification email sent.',
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->loadMissing(['role', 'platformAdmin', 'teamMembership']);
        }

        return response()->json([
            'user' => $user,
            'meta' => $user ? $this->buildMeta($user) : null,
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
