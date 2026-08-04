<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BillingPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AttendanceService;
use App\Services\Auth\SocialAuthProviderRegistry;
use App\Services\Auth\WebLoginResponseService;
use App\Services\SecurityEventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'authContext' => $this->authContext($request),
            'socialCreatePrompt' => $this->socialCreatePrompt($request),
        ]);
    }

    /**
     * Expose a pending "no account, confirm creation" prompt from the social login
     * flow. Only safe display fields are sent to the client; OAuth tokens and the
     * raw profile never leave the server.
     *
     * @return array{provider: string, provider_label: string, email: string, token: string, confirm_url: string}|null
     */
    private function socialCreatePrompt(Request $request): ?array
    {
        $candidate = $request->session()->get('social_auth.create_candidate');
        if (! is_array($candidate)) {
            return null;
        }

        $provider = (string) ($candidate['provider'] ?? '');
        $token = (string) ($candidate['token'] ?? '');
        if ($provider === '' || $token === '') {
            return null;
        }

        $resolvedProvider = app(SocialAuthProviderRegistry::class)->provider($provider);
        $email = (string) (($candidate['profile'] ?? [])['provider_email'] ?? '');

        return [
            'provider' => $provider,
            'provider_label' => (string) ($resolvedProvider['label'] ?? ucfirst($provider)),
            'email' => $this->maskEmail($email),
            'token' => $token,
            'confirm_url' => route('auth.social.confirm', ['provider' => $provider]),
        ];
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        $atPosition = strpos($email, '@');
        if ($atPosition === false || $atPosition === 0) {
            return $email;
        }

        $local = substr($email, 0, $atPosition);
        $domain = substr($email, $atPosition);
        $visible = substr($local, 0, 1);

        return $visible.str_repeat('*', max(1, strlen($local) - 1)).$domain;
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return app(WebLoginResponseService::class)->respond($request, $request->user(), [
            'auth_method' => 'password',
            ...$this->authContext($request),
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();
        if ($user) {
            app(AttendanceService::class)->autoClockOut($user);
            app(SecurityEventService::class)->record($user, 'auth.logout', $request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('welcome'));
        }

        return redirect()->route('welcome');
    }

    /**
     * @return array{source: string, plan: ?string, billing_period: ?string}
     */
    private function authContext(Request $request): array
    {
        $source = trim((string) ($request->input('source') ?? $request->query('source') ?? 'login'));
        if (! in_array($source, ['login', 'register', 'onboarding'], true)) {
            $source = 'login';
        }

        return [
            'source' => $source,
            'plan' => $source === 'onboarding'
                ? $this->normalizeOptionalString($request->input('plan') ?? $request->query('plan'))
                : null,
            'billing_period' => $source === 'onboarding'
                ? BillingPeriod::tryFromMixed($request->input('billing_period') ?? $request->query('billing_period'))?->value
                : null,
        ];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
