<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingIsComplete
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        if ($user->isClient()) {
            return $next($request);
        }

        if ($user->isSuperadmin() || $user->isPlatformAdmin()) {
            return $next($request);
        }

        if (!$user->isAccountOwner()) {
            return $next($request);
        }

        $route = $request->route();
        if ($route?->named('onboarding.*')
            || $route?->named('api.onboarding.*')
            || $route?->named('logout')
            || $route?->named('verification.*')
            || $route?->named('password.*')
        ) {
            return $next($request);
        }

        if ($user->onboarding_completed_at) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Onboarding required.',
                'onboarding_required' => true,
            ], 409);
        }

        return redirect()->route('onboarding.index');
    }
}
