<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotSuspended
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_suspended && !$user->isSuperadmin()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Account suspended. Please contact support.',
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
                ->with('error', 'Account suspended. Please contact support.');
        }

        return $next($request);
    }
}
