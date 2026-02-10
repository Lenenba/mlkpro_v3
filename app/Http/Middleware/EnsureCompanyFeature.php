<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyFeature
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        if ($user->isSuperadmin()) {
            return $next($request);
        }

        if (!$user->hasCompanyFeature($feature)) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Module unavailable for your plan.',
                ], 403);
            }

            $message = 'Module indisponible pour votre plan.';
            $previous = url()->previous();
            $current = $request->fullUrl();
            $fallback = route('dashboard');
            $target = $previous && $previous !== $current ? $previous : $fallback;

            return redirect()->to($target)->with('warning', $message);
        }

        return $next($request);
    }
}
