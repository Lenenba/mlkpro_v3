<?php

namespace App\Http\Controllers\Auth;

use App\Enums\BillingPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AttendanceService;
use App\Services\Auth\WebLoginResponseService;
use App\Services\SecurityEventService;
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
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'authContext' => $this->authContext($request),
        ]);
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
