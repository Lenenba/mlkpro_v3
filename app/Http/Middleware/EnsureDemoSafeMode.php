<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoSafeMode
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !(bool) $user->is_demo_user) {
            return $next($request);
        }

        if (!config('demo.enabled')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if ($routeName && $this->matches($routeName, $this->blockedRoutes())) {
            abort(403, 'Demo mode: this action is disabled.');
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($routeName && $this->matches($routeName, $this->allowedRoutes())) {
            return $next($request);
        }

        abort(403, 'Demo mode: this action is disabled.');
    }

    private function matches(string $routeName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($routeName === $pattern || Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    private function blockedRoutes(): array
    {
        return [
            'profile.update',
            'profile.destroy',
            'password.update',
            'settings.billing.*',
            'settings.api-tokens.*',
            'product.export',
            'product.import',
            'superadmin.*',
        ];
    }

    private function allowedRoutes(): array
    {
        return [
            'customer.*',
            'customer.quote.*',
            'quote.*',
            'work.*',
            'jobs.*',
            'task.*',
            'invoice.*',
            'payment.store',
            'product.*',
            'service.*',
            'request.*',
            'sales.*',
            'orders.*',
            'settings.company.update',
            'settings.notifications.update',
            'settings.categories.*',
            'settings.warehouses.*',
            'notifications.read-all',
            'demo.*',
        ];
    }
}
