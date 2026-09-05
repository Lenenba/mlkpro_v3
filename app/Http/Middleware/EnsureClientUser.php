<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientUser
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
            abort(403);
        }

        $user->loadMissing('role');
        if (! $user->isClient()) {
            abort(403);
        }

        $this->portalAccessService->customer($request);

        return $next($request);
    }
}
