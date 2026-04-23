<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\LocalePreference;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialAuthAccountService
{
    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $tokens
     * @return array{user: User, social_account: UserSocialAccount, was_created: bool, was_linked: bool}
     */
    public function resolve(
        string $provider,
        array $profile,
        array $tokens,
        Request $request
    ): array {
        $providerLabel = ucfirst($provider);
        $providerUserId = trim((string) ($profile['provider_user_id'] ?? ''));
        $providerEmail = strtolower(trim((string) ($profile['provider_email'] ?? '')));
        $emailVerified = (bool) ($profile['provider_email_verified'] ?? false);

        if ($providerUserId === '') {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.profile_incomplete', ['provider' => $providerLabel]),
            ]);
        }

        if ($providerEmail === '' || ! $emailVerified) {
            throw ValidationException::withMessages([
                'provider' => __('ui.auth.social.email_not_verified', ['provider' => $providerLabel]),
            ]);
        }

        $socialAccount = UserSocialAccount::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        $wasCreated = false;
        $wasLinked = false;

        if ($socialAccount) {
            $user = $socialAccount->user;

            if (! $user) {
                throw ValidationException::withMessages([
                    'provider' => __('ui.auth.social.account_not_available', ['provider' => $providerLabel]),
                ]);
            }
        } else {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$providerEmail])
                ->first();

            if ($user) {
                $conflictingAccount = UserSocialAccount::query()
                    ->where('user_id', $user->id)
                    ->where('provider', $provider)
                    ->first();

                if ($conflictingAccount) {
                    throw ValidationException::withMessages([
                        'provider' => __('ui.auth.social.provider_already_linked', ['provider' => $providerLabel]),
                    ]);
                }

                $socialAccount = new UserSocialAccount;
                $wasLinked = true;
            } else {
                $user = $this->createOwnerFromSocialProfile($providerEmail, $profile, $request);
                $socialAccount = new UserSocialAccount;
                $wasCreated = true;
                $wasLinked = true;
            }
        }

        $profileName = trim((string) ($profile['provider_name'] ?? ''));
        $avatarUrl = trim((string) ($profile['provider_avatar_url'] ?? ''));
        $now = now();

        if (! $user->email_verified_at) {
            $user->forceFill([
                'email_verified_at' => $now,
            ])->save();
        }

        if (! LocalePreference::isSupported($user->locale)) {
            $user->forceFill([
                'locale' => LocalePreference::forRequest($request, $user),
            ])->save();
        }

        if (trim((string) $user->name) === '' && $profileName !== '') {
            $user->forceFill(['name' => $profileName])->save();
        }

        if (! $user->profile_picture && $avatarUrl !== '') {
            $user->forceFill(['profile_picture' => $avatarUrl])->save();
        }

        $socialAccount->forceFill([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'provider_email' => $providerEmail,
            'provider_email_verified_at' => $emailVerified ? $now : null,
            'provider_name' => $profileName !== '' ? $profileName : null,
            'provider_avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            'access_token' => $this->nullableString($tokens['access_token'] ?? null),
            'refresh_token' => $this->nullableString($tokens['refresh_token'] ?? null),
            'token_expires_at' => $tokens['token_expires_at'] ?? null,
            'last_login_at' => $now,
            'metadata' => array_filter([
                'token_type' => $this->nullableString($tokens['token_type'] ?? null),
                'granted_scopes' => array_values((array) ($tokens['granted_scopes'] ?? [])),
                'id_token_present' => ! empty($tokens['id_token']),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        ])->save();

        return [
            'user' => $user->fresh(),
            'social_account' => $socialAccount->fresh(),
            'was_created' => $wasCreated,
            'was_linked' => $wasLinked,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function createOwnerFromSocialProfile(string $email, array $profile, Request $request): User
    {
        $roleId = Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        )->id;

        $name = trim((string) ($profile['provider_name'] ?? ''));
        $avatarUrl = trim((string) ($profile['provider_avatar_url'] ?? ''));

        $user = User::create([
            'name' => $name !== '' ? $name : Str::before($email, '@'),
            'email' => $email,
            'locale' => LocalePreference::forRequest($request),
            'password' => Hash::make(Str::random(40)),
            'role_id' => $roleId,
            'profile_picture' => $avatarUrl !== '' ? $avatarUrl : null,
        ]);

        $user->forceFill([
            'email_verified_at' => Carbon::now(),
        ])->save();

        event(new Registered($user));

        return $user;
    }

    private function nullableString(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
