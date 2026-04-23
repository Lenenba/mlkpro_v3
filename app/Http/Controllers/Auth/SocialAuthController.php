<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BillingPeriod;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleSocialAuthService;
use App\Services\Auth\SocialAuthAccountService;
use App\Services\Auth\SocialAuthProviderRegistry;
use App\Services\Auth\WebLoginResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SocialAuthController extends Controller
{
    public function redirect(
        Request $request,
        string $provider,
        SocialAuthProviderRegistry $registry
    ): RedirectResponse {
        $resolvedProvider = $registry->provider($provider);
        abort_if(! $resolvedProvider, 404);

        $source = $this->resolveSource($request, $resolvedProvider);
        $plan = $this->resolvePlan($request, $source);
        $billingPeriod = $this->resolveBillingPeriod($request, $source);

        $request->session()->put('social_auth.pending', [
            'provider' => $resolvedProvider['key'],
            'source' => $source,
            'plan' => $plan,
            'billing_period' => $billingPeriod,
            'requested_at' => now()->toIso8601String(),
        ]);

        if (! ($resolvedProvider['enabled'] ?? false)) {
            abort(404);
        }

        if (! ($resolvedProvider['configured'] ?? false)) {
            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'error',
                __('ui.auth.social.provider_not_configured', ['provider' => $resolvedProvider['label']])
            );
        }

        if (! ($resolvedProvider['implemented'] ?? false)) {
            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'warning',
                __('ui.auth.social.provider_not_ready', ['provider' => $resolvedProvider['label']])
            );
        }

        if ($resolvedProvider['key'] === 'google') {
            try {
                $state = (string) str()->random(64);
                $authorizationUrl = app(GoogleSocialAuthService::class)->authorizationUrl($resolvedProvider, $state);

                $request->session()->put('social_auth.pending', [
                    'provider' => $resolvedProvider['key'],
                    'source' => $source,
                    'plan' => $plan,
                    'billing_period' => $billingPeriod,
                    'state' => $state,
                    'requested_at' => now()->toIso8601String(),
                ]);

                return redirect()->away($authorizationUrl);
            } catch (ValidationException $exception) {
                return $this->redirectToSource(
                    $source,
                    $plan,
                    $billingPeriod,
                    'error',
                    $this->validationMessage($exception, __('ui.auth.social.provider_not_configured', ['provider' => $resolvedProvider['label']]))
                );
            }
        }

        return $this->redirectToSource(
            $source,
            $plan,
            $billingPeriod,
            'warning',
            __('ui.auth.social.provider_not_ready', ['provider' => $resolvedProvider['label']])
        );
    }

    public function callback(
        Request $request,
        string $provider,
        SocialAuthProviderRegistry $registry
    ): RedirectResponse {
        $resolvedProvider = $registry->provider($provider);
        abort_if(! $resolvedProvider || ! ($resolvedProvider['enabled'] ?? false), 404);

        $pending = $request->session()->get('social_auth.pending', []);
        $source = is_array($pending) ? (string) ($pending['source'] ?? config('social_auth.default_source', 'login')) : 'login';
        $plan = is_array($pending) ? $this->normalizeOptionalString($pending['plan'] ?? null) : null;
        $billingPeriod = is_array($pending)
            ? BillingPeriod::tryFromMixed($pending['billing_period'] ?? null)?->value
            : null;

        if (! is_array($pending) || (string) ($pending['provider'] ?? '') !== $resolvedProvider['key']) {
            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'error',
                __('ui.auth.social.invalid_state')
            );
        }

        if (! ($resolvedProvider['implemented'] ?? false)) {
            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'warning',
                __('ui.auth.social.callback_not_ready', ['provider' => $resolvedProvider['label']])
            );
        }

        if ($resolvedProvider['key'] !== 'google') {
            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'warning',
                __('ui.auth.social.callback_not_ready', ['provider' => $resolvedProvider['label']])
            );
        }

        try {
            $result = app(GoogleSocialAuthService::class)->authenticate($request, $resolvedProvider, $pending);
            $resolved = app(SocialAuthAccountService::class)->resolve(
                $resolvedProvider['key'],
                $result['profile'],
                $result['tokens'],
                $request
            );
        } catch (ValidationException $exception) {
            $request->session()->forget('social_auth.pending');

            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'error',
                $this->validationMessage($exception, __('ui.auth.social.callback_not_ready', ['provider' => $resolvedProvider['label']]))
            );
        } catch (\Throwable $exception) {
            report($exception);
            $request->session()->forget('social_auth.pending');

            return $this->redirectToSource(
                $source,
                $plan,
                $billingPeriod,
                'error',
                __('ui.auth.social.callback_failed', ['provider' => $resolvedProvider['label']])
            );
        }

        $request->session()->forget('social_auth.pending');
        Auth::login($resolved['user']);
        $request->session()->regenerate();

        return app(WebLoginResponseService::class)->respond($request, $resolved['user'], [
            'auth_method' => 'social',
            'provider' => $resolvedProvider['key'],
            'source' => $source,
            'plan' => $plan,
            'billing_period' => $billingPeriod,
        ]);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function resolveSource(Request $request, array $provider): string
    {
        $source = trim((string) $request->query('source', config('social_auth.default_source', 'login')));
        if (! in_array($source, ['login', 'register', 'onboarding'], true)) {
            $source = 'login';
        }

        abort_if(! ($provider['contexts'][$source] ?? false), 404);

        return $source;
    }

    private function resolvePlan(Request $request, string $source): ?string
    {
        if ($source !== 'onboarding') {
            return null;
        }

        return $this->normalizeOptionalString($request->query('plan'));
    }

    private function resolveBillingPeriod(Request $request, string $source): ?string
    {
        if ($source !== 'onboarding') {
            return null;
        }

        return BillingPeriod::tryFromMixed($request->query('billing_period'))?->value;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function redirectToSource(
        string $source,
        ?string $plan,
        ?string $billingPeriod,
        string $flashKey,
        string $message
    ): RedirectResponse {
        $response = match ($source) {
            'onboarding' => redirect()->route('onboarding.index', array_filter([
                'plan' => $plan,
                'billing_period' => $billingPeriod,
            ], static fn (?string $value): bool => $value !== null && $value !== '')),
            'register' => redirect()->route('register'),
            default => redirect()->route('login'),
        };

        return $response->with($flashKey, $message);
    }

    private function validationMessage(ValidationException $exception, string $fallback): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->first();

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
