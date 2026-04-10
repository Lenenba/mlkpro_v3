<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\TotpService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SecuritySettingsController extends Controller
{
    private const ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.password_changed',
        'auth.2fa.sent',
        'auth.2fa.resend',
    ];

    private const APP_SETUP_PREFIX = 'two-factor-app-setup:';

    private const APP_SETUP_USER_PREFIX = 'two-factor-app-setup-user:';

    private const APP_SETUP_TTL_MINUTES = 15;

    public function edit(Request $request)
    {
        $user = $this->resolveSecurityViewer($request);

        return $this->inertiaOrJson('Settings/Security', $this->buildSecurityPayload($request, $user));
    }

    public function startAppSetup(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->resolveSecurityConfigurator($request);
        $secret = app(TotpService::class)->generateSecret();
        $appSetup = null;

        if ($this->shouldReturnJson($request)) {
            $appSetup = $this->storeApiAppSetup($user, $secret);
        } else {
            $request->session()->put('two_factor_app_setup_secret', $secret);
        }

        return $this->securitySuccessResponse(
            $request,
            $user,
            'Authentificateur en preparation.',
            $appSetup,
            201
        );
    }

    public function confirmAppSetup(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->resolveSecurityConfigurator($request);

        $rules = [
            'code' => 'required|string|min:6|max:10',
        ];

        if ($this->shouldReturnJson($request)) {
            $rules['setup_token'] = 'required|string';
        }

        $validated = $request->validate($rules);

        $secret = null;
        if ($this->shouldReturnJson($request)) {
            $appSetup = $this->resolveApiAppSetup($user, (string) $validated['setup_token']);
            if (!$appSetup) {
                return $this->securityValidationErrorResponse(
                    $request,
                    'setup_token',
                    'Demarrez la configuration avant de valider.'
                );
            }

            $secret = (string) $appSetup['secret'];
        } else {
            $secret = $request->session()->get('two_factor_app_setup_secret');
            if (!$secret) {
                return $this->securityValidationErrorResponse(
                    $request,
                    'code',
                    'Demarrez la configuration avant de valider.'
                );
            }
        }

        $verified = app(TotpService::class)->verifyCode($secret, $validated['code']);
        if (!$verified) {
            return $this->securityValidationErrorResponse($request, 'code', 'Code invalide.');
        }

        $user->forceFill([
            'two_factor_method' => 'app',
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_last_sent_at' => null,
        ])->save();

        $this->clearPendingAppSetup($request, $user);
        $user->refresh();

        return $this->securitySuccessResponse($request, $user, 'Authentificateur active.');
    }

    public function cancelAppSetup(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->resolveSecurityConfigurator($request);
        $this->clearPendingAppSetup($request, $user);

        if ($this->shouldReturnJson($request)) {
            return $this->securitySuccessResponse(
                $request,
                $user,
                'Configuration d authentificateur annulee.'
            );
        }

        return redirect()->back();
    }

    public function switchToEmail(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->resolveSecurityConfigurator($request);

        $user->forceFill([
            'two_factor_method' => 'email',
            'two_factor_secret' => null,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ])->save();

        $this->clearPendingAppSetup($request, $user);
        $user->refresh();

        return $this->securitySuccessResponse($request, $user, '2FA par email active.');
    }

    public function switchToSms(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->resolveSecurityConfigurator($request);

        $smsCapability = app(TwoFactorService::class)->smsCapability($user);
        if (empty($smsCapability['available'])) {
            $error = '2FA SMS indisponible.';
            if (empty($smsCapability['company_enabled'])) {
                $error = 'Activez d abord le 2FA SMS dans Parametres > Entreprise.';
            } elseif (empty($smsCapability['has_phone'])) {
                $error = 'Ajoutez d abord un numero de telephone au profil.';
            } elseif (empty($smsCapability['twilio_configured'])) {
                $error = 'Configuration SMS non disponible. Contactez le support.';
            }

            return $this->securityValidationErrorResponse($request, 'two_factor_method', $error);
        }

        $user->forceFill([
            'two_factor_method' => 'sms',
            'two_factor_secret' => null,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_last_sent_at' => null,
        ])->save();

        $this->clearPendingAppSetup($request, $user);
        $user->refresh();

        return $this->securitySuccessResponse($request, $user, '2FA par SMS active.');
    }

    private function buildSecurityPayload(Request $request, User $user): array
    {
        $canViewTeam = $user->isAccountOwner();
        $ownerId = $user->accountOwnerId();
        $activity = $this->buildActivityPayload($user, $ownerId, $canViewTeam);

        return [
            'two_factor' => $this->buildTwoFactorPayload($user, $this->resolvePendingAppSetup($request, $user)),
            'rate_limit' => config('services.rate_limits.api_per_user'),
            'can_view_team' => $canViewTeam,
            'activity' => $activity,
        ];
    }

    private function buildActivityPayload(User $user, int $ownerId, bool $canViewTeam): array
    {
        $userIds = $canViewTeam
            ? TeamMember::query()
                ->forAccount($ownerId)
                ->pluck('user_id')
                ->push($ownerId)
                ->filter()
                ->unique()
                ->values()
            : collect([$user->id]);

        $userMap = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'profile_picture'])
            ->keyBy('id');

        $userMorph = (new User())->getMorphClass();

        return ActivityLog::query()
            ->where('subject_type', $userMorph)
            ->whereIn('subject_id', $userIds)
            ->whereIn('action', self::ACTIONS)
            ->latest()
            ->limit(50)
            ->get(['id', 'user_id', 'subject_id', 'action', 'properties', 'created_at'])
            ->map(function (ActivityLog $log) use ($userMap) {
                $subject = $userMap->get($log->subject_id);
                $actor = $userMap->get($log->user_id);
                $properties = (array) ($log->properties ?? []);

                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'ip' => $properties['ip'] ?? null,
                    'user_agent' => $properties['user_agent'] ?? null,
                    'channel' => $properties['channel'] ?? null,
                    'two_factor' => $properties['two_factor'] ?? null,
                    'device' => $properties['device'] ?? null,
                    'subject' => $this->buildActivityUserPayload($subject),
                    'actor' => $this->buildActivityUserPayload($actor),
                ];
            })
            ->values()
            ->all();
    }

    private function buildActivityUserPayload(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_picture' => $user->profile_picture,
            'profile_picture_url' => $user->profile_picture_url,
        ];
    }

    private function buildTwoFactorPayload(User $user, ?array $appSetup = null): array
    {
        $smsCapability = app(TwoFactorService::class)->smsCapability($user);

        return [
            'required' => $user->requiresTwoFactor(),
            'enabled' => (bool) $user->two_factor_enabled,
            'method' => $user->twoFactorMethod(),
            'has_app' => !empty($user->two_factor_secret),
            'can_configure' => $user->isAccountOwner() && !$user->isSuperadmin() && !$user->isPlatformAdmin(),
            'app_setup' => $appSetup,
            'email' => $user->email,
            'phone_hint' => $smsCapability['phone_hint'] ?? null,
            'last_sent_at' => $user->two_factor_last_sent_at?->toIso8601String(),
            'sms' => $smsCapability,
        ];
    }

    private function resolvePendingAppSetup(Request $request, User $user): ?array
    {
        if ($this->shouldReturnJson($request)) {
            return $this->resolveActiveApiAppSetup($user);
        }

        $setupSecret = $request->session()->get('two_factor_app_setup_secret');
        if (!$setupSecret) {
            return null;
        }

        return [
            'secret' => $setupSecret,
            'otpauth_url' => $this->buildOtpAuthUrl($user, $setupSecret),
        ];
    }

    private function storeApiAppSetup(User $user, string $secret): array
    {
        $this->forgetApiAppSetup($user);

        $setupToken = Str::random(64);
        $expiresAt = now()->addMinutes(self::APP_SETUP_TTL_MINUTES);
        $expiresAtIso = $expiresAt->toIso8601String();

        Cache::put($this->appSetupCacheKey($setupToken), [
            'user_id' => $user->id,
            'secret' => $secret,
            'expires_at' => $expiresAtIso,
        ], $expiresAt);

        Cache::put($this->appSetupUserCacheKey($user), $setupToken, $expiresAt);

        return $this->buildApiAppSetupPayload($user, $setupToken, $secret, $expiresAtIso);
    }

    private function resolveActiveApiAppSetup(User $user): ?array
    {
        $setupToken = Cache::get($this->appSetupUserCacheKey($user));
        if (!is_string($setupToken) || $setupToken === '') {
            return null;
        }

        return $this->resolveApiAppSetup($user, $setupToken);
    }

    private function resolveApiAppSetup(User $user, string $setupToken): ?array
    {
        $activeToken = Cache::get($this->appSetupUserCacheKey($user));
        if (!is_string($activeToken) || $activeToken !== $setupToken) {
            return null;
        }

        $data = Cache::get($this->appSetupCacheKey($setupToken));
        if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== $user->id) {
            $this->forgetApiAppSetup($user);

            return null;
        }

        $secret = (string) ($data['secret'] ?? '');
        if ($secret === '') {
            $this->forgetApiAppSetup($user);

            return null;
        }

        $expiresAt = $data['expires_at'] ?? null;

        return $this->buildApiAppSetupPayload(
            $user,
            $setupToken,
            $secret,
            is_string($expiresAt) ? $expiresAt : null
        );
    }

    private function buildApiAppSetupPayload(
        User $user,
        string $setupToken,
        string $secret,
        ?string $expiresAt
    ): array {
        return [
            'setup_token' => $setupToken,
            'secret' => $secret,
            'otpauth_url' => $this->buildOtpAuthUrl($user, $secret),
            'expires_at' => $expiresAt,
        ];
    }

    private function buildOtpAuthUrl(User $user, string $secret): string
    {
        $issuer = (string) config('app.name', 'App');
        $accountName = $user->email ?: ('user-'.$user->id);

        return app(TotpService::class)->otpAuthUrl($issuer, $accountName, $secret);
    }

    private function clearPendingAppSetup(Request $request, User $user): void
    {
        if ($this->shouldReturnJson($request)) {
            $this->forgetApiAppSetup($user);

            return;
        }

        $request->session()->forget('two_factor_app_setup_secret');
    }

    private function forgetApiAppSetup(User $user): void
    {
        $setupToken = Cache::get($this->appSetupUserCacheKey($user));

        if (is_string($setupToken) && $setupToken !== '') {
            Cache::forget($this->appSetupCacheKey($setupToken));
        }

        Cache::forget($this->appSetupUserCacheKey($user));
    }

    private function appSetupCacheKey(string $setupToken): string
    {
        return self::APP_SETUP_PREFIX.$setupToken;
    }

    private function appSetupUserCacheKey(User $user): string
    {
        return self::APP_SETUP_USER_PREFIX.$user->id;
    }

    private function resolveSecurityViewer(Request $request): User
    {
        $user = $request->user();
        if (!$user || $user->isClient() || $user->isSuperadmin()) {
            abort(403);
        }

        return $user;
    }

    private function resolveSecurityConfigurator(Request $request): User
    {
        $user = $this->resolveSecurityViewer($request);

        if (!$user->isAccountOwner() || $user->isPlatformAdmin()) {
            abort(403);
        }

        return $user;
    }

    private function securitySuccessResponse(
        Request $request,
        User $user,
        string $message,
        ?array $appSetup = null,
        int $status = 200
    ): RedirectResponse|JsonResponse {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'two_factor' => $this->buildTwoFactorPayload($user, $appSetup),
            ], $status);
        }

        return redirect()->back()->with('success', $message);
    }

    private function securityValidationErrorResponse(
        Request $request,
        string $field,
        string $message
    ): RedirectResponse|JsonResponse {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'errors' => [
                    $field => [$message],
                ],
            ], 422);
        }

        return redirect()->back()->withErrors([
            $field => $message,
        ]);
    }
}
