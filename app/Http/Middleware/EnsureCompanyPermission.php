<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyPermission
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $accountId = $user->accountOwnerId();
        $requiredPermissions = collect($permissions)
            ->flatMap(fn (string $permission): array => preg_split('/[|,]/', $permission) ?: [])
            ->map(fn (string $permission): string => trim($permission))
            ->filter()
            ->values();

        $allowed = $requiredPermissions->isEmpty()
            || $requiredPermissions->contains(
                fn (string $permission): bool => Gate::forUser($user)->allows('company-permission', [$permission, $accountId])
            );

        if (! $allowed) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Permission denied.',
                ], 403);
            }

            abort(403);
        }

        return $next($request);
    }
}
