<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Demo\DemoWorkspaceTimelineService;
use App\Services\SecurityEventService;
use App\Services\TwoFactorService;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebLoginResponseService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function respond(Request $request, User $user, array $context = []): RedirectResponse
    {
        $resolvedLocale = LocalePreference::forRequest($request, $user);

        if (! LocalePreference::isSupported($user->locale)) {
            $user->forceFill(['locale' => $resolvedLocale])->save();
        }

        app()->setLocale($resolvedLocale);
        $request->session()->put('locale', $resolvedLocale);

        if ($user->is_suspended) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => __('ui.auth.account_suspended')]);
        }

        if ($user->requiresTwoFactor()) {
            $twoFactorService = app(TwoFactorService::class);
            $effectiveMethod = $twoFactorService->resolveEffectiveMethod($user);

            if ($effectiveMethod !== TwoFactorService::METHOD_APP) {
                $result = $twoFactorService->sendCode($user, true, $effectiveMethod);
                if (! ($result['sent'] ?? false)) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()
                        ->route('login')
                        ->withErrors(['email' => __('ui.auth.two_factor_delivery_failed')]);
                }

                $effectiveMethod = (string) ($result['method'] ?? $effectiveMethod);
                app(SecurityEventService::class)->record($user, 'auth.2fa.sent', $request, [
                    'reason' => 'login',
                    'method' => $effectiveMethod,
                    ...$this->eventContext($context),
                ]);
            }

            $request->session()->put([
                'two_factor_passed' => false,
                'two_factor_pending' => true,
                'two_factor_delivery_method' => $effectiveMethod,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        app(AttendanceService::class)->autoClockIn($user);
        app(SecurityEventService::class)->record($user, 'auth.login', $request, [
            'two_factor' => false,
            ...$this->eventContext($context),
        ]);
        app(DemoWorkspaceTimelineService::class)->recordLoginForUser($user, [
            'two_factor' => false,
            ...$this->eventContext($context),
        ]);

        if ($user->isAccountOwner() && ! $user->onboarding_completed_at && ! $user->isSuperadmin() && ! $user->isPlatformAdmin()) {
            return redirect()->route('onboarding.index', array_filter([
                'plan' => $this->nullableString($context['plan'] ?? null),
                'billing_period' => $this->nullableString($context['billing_period'] ?? null),
            ], static fn (?string $value): bool => $value !== null && $value !== ''));
        }

        if ($user->must_change_password) {
            return redirect()
                ->route('profile.edit')
                ->with('warning', __('ui.auth.update_temporary_password'));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function eventContext(array $context): array
    {
        return array_filter([
            'auth_method' => $this->nullableString($context['auth_method'] ?? null),
            'auth_provider' => $this->nullableString($context['provider'] ?? null),
            'auth_source' => $this->nullableString($context['source'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function nullableString(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
