<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientPortalAccess
{
    public function __construct(
        private readonly PortalAccessService $portalAccessService,
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $user->loadMissing('role');
        if (! $user->isClient() || $this->portalAccessService->clientHasPortalAccess($user)) {
            return $next($request);
        }

        $message = __('ui.auth.portal_access_disabled');

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $message,
            ], 403);
        }

        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors(['email' => $message]);
    }
}
