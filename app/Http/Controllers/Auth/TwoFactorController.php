<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Services\SecurityEventService;
use App\Services\TotpService;
use App\Services\TwoFactorService;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->requiresTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $this->syncLocale($request, $user);

        $service = app(TwoFactorService::class);
        $sessionMethod = $request->session()->get('two_factor_delivery_method');
        $effectiveMethod = $service->resolveEffectiveMethod(
            $user,
            is_string($sessionMethod) ? $sessionMethod : null
        );

        if ($effectiveMethod !== TwoFactorService::METHOD_APP) {
            if (! $user->two_factor_expires_at || now()->greaterThan($user->two_factor_expires_at)) {
                $result = $service->sendCode($user, true, $effectiveMethod);
                if (! ($result['sent'] ?? false)) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->withErrors([
                        'email' => __('ui.auth.two_factor.challenge_delivery_failed'),
                    ]);
                }

                $effectiveMethod = (string) ($result['method'] ?? $effectiveMethod);
                $user->refresh();
            }

            $request->session()->put('two_factor_delivery_method', $effectiveMethod);
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'email' => $user->email,
            'phone_hint' => $service->maskedPhoneNumber($user->phone_number),
            'expires_at' => $effectiveMethod !== TwoFactorService::METHOD_APP
                ? $user->two_factor_expires_at?->toIso8601String()
                : null,
            'method' => $effectiveMethod,
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->requiresTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $this->syncLocale($request, $user);

        $validated = $request->validate([
            'code' => 'required|string|min:6|max:10',
        ]);

        $limiterKey = 'two-factor-verify:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);

            return back()->withErrors([
                'code' => __('ui.auth.two_factor.too_many_attempts', ['seconds' => $seconds]),
            ]);
        }

        $service = app(TwoFactorService::class);
        $sessionMethod = $request->session()->get('two_factor_delivery_method');
        $effectiveMethod = $service->resolveEffectiveMethod(
            $user,
            is_string($sessionMethod) ? $sessionMethod : null
        );
        $code = trim($validated['code']);

        $verified = false;
        if ($effectiveMethod === TwoFactorService::METHOD_APP && ! empty($user->two_factor_secret)) {
            $verified = app(TotpService::class)->verifyCode($user->two_factor_secret, $code);
        } else {
            $verified = $service->verifyCode($user, $code);
        }

        if (! $verified) {
            RateLimiter::hit($limiterKey);

            return back()->withErrors([
                'code' => __('ui.auth.two_factor.invalid_or_expired'),
            ]);
        }

        if ($effectiveMethod === TwoFactorService::METHOD_APP && ! $user->two_factor_enabled) {
            $user->forceFill([
                'two_factor_enabled' => true,
            ])->save();
        }

        RateLimiter::clear($limiterKey);
        $request->session()->put('two_factor_passed', true);
        $request->session()->forget(['two_factor_pending', 'two_factor_delivery_method']);

        app(SecurityEventService::class)->record($user, 'auth.login', $request, [
            'two_factor' => true,
        ]);
        app(DemoWorkspaceTimelineService::class)->recordLoginForUser($user, [
            'two_factor' => true,
        ]);
        app(AttendanceService::class)->autoClockIn($user);

        if ($user->isAccountOwner() && ! $user->onboarding_completed_at && ! $user->isSuperadmin() && ! $user->isPlatformAdmin()) {
            return redirect()->route('onboarding.index');
        }

        if ($user->must_change_password) {
            return redirect()
                ->route('profile.edit')
                ->with('warning', __('ui.auth.update_temporary_password'));
        }

        return redirect()->intended(route('dashboard'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user || ! $user->requiresTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $this->syncLocale($request, $user);

        $limiterKey = 'two-factor-resend:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            $seconds = RateLimiter::availableIn($limiterKey);

            return back()->withErrors([
                'code' => __('ui.auth.two_factor.resend_wait', ['seconds' => $seconds]),
            ]);
        }

        $service = app(TwoFactorService::class);
        $sessionMethod = $request->session()->get('two_factor_delivery_method');
        $effectiveMethod = $service->resolveEffectiveMethod(
            $user,
            is_string($sessionMethod) ? $sessionMethod : null
        );

        if ($effectiveMethod === TwoFactorService::METHOD_APP) {
            return back()->withErrors([
                'code' => __('ui.auth.two_factor.app_resend_unavailable'),
            ]);
        }

        $result = $service->sendCode($user, false, $effectiveMethod);
        if (! $result['sent']) {
            if (($result['reason'] ?? null) === 'cooldown') {
                return back()->withErrors([
                    'code' => __('ui.auth.two_factor.resend_wait', ['seconds' => $result['retry_after']]),
                ]);
            }

            return back()->withErrors([
                'code' => __('ui.auth.two_factor.resend_failed'),
            ]);
        }

        $request->session()->put('two_factor_delivery_method', $result['method'] ?? $effectiveMethod);
        RateLimiter::hit($limiterKey);

        app(SecurityEventService::class)->record($user, 'auth.2fa.resend', $request, [
            'reason' => 'resend',
            'method' => $result['method'] ?? $effectiveMethod,
        ]);

        return back()->with('status', __('ui.auth.two_factor.resent'));
    }

    private function syncLocale(Request $request, User $user): void
    {
        $locale = LocalePreference::forRequest($request, $user);

        app()->setLocale($locale);
        $request->session()->put('locale', $locale);

        if (! LocalePreference::isSupported($user->locale)) {
            $user->forceFill([
                'locale' => $locale,
            ])->save();
        }
    }
}
