<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AttendanceService;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Services\SecurityEventService;
use App\Services\TwoFactorService;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();
        $resolvedLocale = $user
            ? LocalePreference::forRequest($request, $user)
            : LocalePreference::forRequest($request);

        if ($user && ! LocalePreference::isSupported($user->locale)) {
            $user->forceFill(['locale' => $resolvedLocale])->save();
        }

        app()->setLocale($resolvedLocale);
        $request->session()->put('locale', $resolvedLocale);

        if ($user?->is_suspended) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->back()
                ->withErrors(['email' => __('ui.auth.account_suspended')]);
        }

        if ($user?->requiresTwoFactor()) {
            $twoFactorService = app(TwoFactorService::class);
            $effectiveMethod = $twoFactorService->resolveEffectiveMethod($user);

            if ($effectiveMethod !== TwoFactorService::METHOD_APP) {
                $result = $twoFactorService->sendCode($user, true, $effectiveMethod);
                if (! ($result['sent'] ?? false)) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()
                        ->back()
                        ->withErrors(['email' => __('ui.auth.two_factor_delivery_failed')]);
                }

                $effectiveMethod = (string) ($result['method'] ?? $effectiveMethod);
                app(SecurityEventService::class)->record($user, 'auth.2fa.sent', $request, [
                    'reason' => 'login',
                    'method' => $effectiveMethod,
                ]);
            }

            $request->session()->put([
                'two_factor_passed' => false,
                'two_factor_pending' => true,
                'two_factor_delivery_method' => $effectiveMethod,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        if ($user) {
            app(AttendanceService::class)->autoClockIn($user);
            app(SecurityEventService::class)->record($user, 'auth.login', $request, [
                'two_factor' => false,
            ]);
            app(DemoWorkspaceTimelineService::class)->recordLoginForUser($user, [
                'two_factor' => false,
            ]);
        }

        if ($user?->isAccountOwner() && ! $user->onboarding_completed_at && ! $user->isSuperadmin() && ! $user->isPlatformAdmin()) {
            return redirect()->route('onboarding.index');
        }

        if ($user?->must_change_password) {
            return redirect()
                ->route('profile.edit')
                ->with('warning', __('ui.auth.update_temporary_password'));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            app(AttendanceService::class)->autoClockOut($user);
            app(SecurityEventService::class)->record($user, 'auth.logout', $request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
