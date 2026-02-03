<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use App\Services\SecurityEventService;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->requiresTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $service = app(TwoFactorService::class);
        if (!$user->two_factor_expires_at || now()->greaterThan($user->two_factor_expires_at)) {
            $service->sendCode($user, true);
            $user->refresh();
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'email' => $user->email,
            'expires_at' => $user->two_factor_expires_at?->toIso8601String(),
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->requiresTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'code' => 'required|string|min:6|max:10',
        ]);

        $limiterKey = 'two-factor-verify:' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            return back()->withErrors([
                'code' => "Trop de tentatives. Reessayez dans {$seconds} secondes.",
            ]);
        }

        $service = app(TwoFactorService::class);
        if (!$service->verifyCode($user, trim($validated['code']))) {
            RateLimiter::hit($limiterKey);
            return back()->withErrors([
                'code' => 'Code invalide ou expire.',
            ]);
        }

        RateLimiter::clear($limiterKey);
        $request->session()->put('two_factor_passed', true);
        $request->session()->forget('two_factor_pending');

        app(SecurityEventService::class)->record($user, 'auth.login', $request, [
            'two_factor' => true,
        ]);

        app(AttendanceService::class)->autoClockIn($user);

        if ($user->isAccountOwner() && !$user->onboarding_completed_at && !$user->isSuperadmin() && !$user->isPlatformAdmin()) {
            return redirect()->route('onboarding.index');
        }

        if ($user->must_change_password) {
            return redirect()
                ->route('profile.edit')
                ->with('warning', 'Please update your temporary password.');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->requiresTwoFactor()) {
            return redirect()->route('dashboard');
        }

        $limiterKey = 'two-factor-resend:' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            return back()->withErrors([
                'code' => "Veuillez patienter {$seconds} secondes avant de demander un nouveau code.",
            ]);
        }

        $service = app(TwoFactorService::class);
        $result = $service->sendCode($user);
        if (!$result['sent']) {
            return back()->withErrors([
                'code' => "Veuillez patienter {$result['retry_after']} secondes avant de demander un nouveau code.",
            ]);
        }

        RateLimiter::hit($limiterKey);

        app(SecurityEventService::class)->record($user, 'auth.2fa.resend', $request, [
            'reason' => 'resend',
        ]);

        return back()->with('status', 'Nouveau code envoye.');
    }
}
