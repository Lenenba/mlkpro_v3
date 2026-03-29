<?php

namespace App\Http\Middleware;

use App\Models\DemoWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoWorkspaceNotExpired
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (! $user->is_demo && ! $user->is_demo_user)) {
            return $next($request);
        }

        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }

        $workspace = DemoWorkspace::query()
            ->select(['id', 'expires_at'])
            ->where('owner_user_id', $user->accountOwnerId())
            ->first();

        if (! $workspace || ! $workspace->isExpired()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This demo workspace has expired.',
            ], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('demo.index')
            ->with('warning', 'This demo workspace has expired. Please request a refreshed access link.');
    }
}
