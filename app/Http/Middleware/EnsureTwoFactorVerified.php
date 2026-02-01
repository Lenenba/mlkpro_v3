<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user || !$user->requiresTwoFactor()) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($request->session()->get('two_factor_passed')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Two-factor authentication required.',
            ], 423);
        }

        if (!$request->session()->has('url.intended')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('two-factor.challenge');
    }
}
