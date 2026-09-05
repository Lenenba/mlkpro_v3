<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalCapabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalCapability
{
    public function __construct(
        private readonly PortalCapabilityService $portalCapabilityService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if (data_get($this->portalCapabilityService->forRequest($request), $capability) === true) {
            return $next($request);
        }

        $message = __('ui.portal.capability_unavailable');

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'code' => 'portal_capability_unavailable',
                'message' => $message,
                'capability' => $capability,
            ], 403);
        }

        $previous = url()->previous();
        $current = $request->fullUrl();
        $target = $previous && $previous !== $current
            ? $previous
            : route('dashboard');

        return redirect()->to($target)->with('warning', $message);
    }
}
