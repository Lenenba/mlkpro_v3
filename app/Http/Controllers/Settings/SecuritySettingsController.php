<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\TotpService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class SecuritySettingsController extends Controller
{
    private const ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.password_changed',
        'auth.2fa.sent',
        'auth.2fa.resend',
    ];

    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->isClient()) {
            abort(403);
        }

        $ownerId = $user->accountOwnerId();
        $canViewTeam = $user->isAccountOwner();

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
        $activity = ActivityLog::query()
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
                    'subject' => $subject ? [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'email' => $subject->email,
                        'profile_picture' => $subject->profile_picture,
                        'profile_picture_url' => $subject->profile_picture_url,
                    ] : null,
                    'actor' => $actor ? [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'email' => $actor->email,
                        'profile_picture' => $actor->profile_picture,
                        'profile_picture_url' => $actor->profile_picture_url,
                    ] : null,
                ];
            })
            ->values();

        $setupSecret = $request->session()->get('two_factor_app_setup_secret');
        $twoFactorSetup = null;
        if ($setupSecret) {
            $issuer = (string) config('app.name', 'App');
            $accountName = $user->email ?: ('user-' . $user->id);
            $otpAuthUrl = app(TotpService::class)->otpAuthUrl($issuer, $accountName, $setupSecret);
            $twoFactorSetup = [
                'secret' => $setupSecret,
                'otpauth_url' => $otpAuthUrl,
            ];
        }

        $twoFactorService = app(TwoFactorService::class);
        $smsCapability = $twoFactorService->smsCapability($user);

        return $this->inertiaOrJson('Settings/Security', [
            'two_factor' => [
                'required' => $user->requiresTwoFactor(),
                'enabled' => (bool) $user->two_factor_enabled,
                'method' => $user->twoFactorMethod(),
                'has_app' => !empty($user->two_factor_secret),
                'can_configure' => $user->isAccountOwner() && !$user->isSuperadmin() && !$user->isPlatformAdmin(),
                'app_setup' => $twoFactorSetup,
                'email' => $user->email,
                'phone_hint' => $smsCapability['phone_hint'] ?? null,
                'last_sent_at' => $user->two_factor_last_sent_at?->toIso8601String(),
                'sms' => $smsCapability,
            ],
            'rate_limit' => config('services.rate_limits.api_per_user'),
            'can_view_team' => $canViewTeam,
            'activity' => $activity,
        ]);
    }

    public function startAppSetup(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner() || $user->isSuperadmin() || $user->isPlatformAdmin()) {
            abort(403);
        }

        $secret = app(TotpService::class)->generateSecret();
        $request->session()->put('two_factor_app_setup_secret', $secret);

        return redirect()->back()->with('success', 'Authentificateur en preparation.');
    }

    public function confirmAppSetup(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner() || $user->isSuperadmin() || $user->isPlatformAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'code' => 'required|string|min:6|max:10',
        ]);

        $secret = $request->session()->get('two_factor_app_setup_secret');
        if (!$secret) {
            return redirect()->back()->withErrors([
                'code' => 'Demarrez la configuration avant de valider.',
            ]);
        }

        $verified = app(TotpService::class)->verifyCode($secret, $validated['code']);
        if (!$verified) {
            return redirect()->back()->withErrors([
                'code' => 'Code invalide.',
            ]);
        }

        $user->forceFill([
            'two_factor_method' => 'app',
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_last_sent_at' => null,
        ])->save();

        $request->session()->forget('two_factor_app_setup_secret');

        return redirect()->back()->with('success', 'Authentificateur active.');
    }

    public function cancelAppSetup(Request $request): RedirectResponse
    {
        $request->session()->forget('two_factor_app_setup_secret');
        return redirect()->back();
    }

    public function switchToEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner() || $user->isSuperadmin() || $user->isPlatformAdmin()) {
            abort(403);
        }

        $user->forceFill([
            'two_factor_method' => 'email',
            'two_factor_secret' => null,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ])->save();

        $request->session()->forget('two_factor_app_setup_secret');

        return redirect()->back()->with('success', '2FA par email active.');
    }

    public function switchToSms(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner() || $user->isSuperadmin() || $user->isPlatformAdmin()) {
            abort(403);
        }

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

            return redirect()->back()->withErrors([
                'two_factor_method' => $error,
            ]);
        }

        $user->forceFill([
            'two_factor_method' => 'sms',
            'two_factor_secret' => null,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_last_sent_at' => null,
        ])->save();

        $request->session()->forget('two_factor_app_setup_secret');

        return redirect()->back()->with('success', '2FA par SMS active.');
    }
}
