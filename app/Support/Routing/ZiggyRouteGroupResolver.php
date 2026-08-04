<?php

namespace App\Support\Routing;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ZiggyRouteGroupResolver
{
    /**
     * @return array<int, string>|string|null
     */
    public function resolve(Request $request): array|string|null
    {
        $routeName = (string) $request->route()?->getName();

        // An Inertia login can redirect to any authenticated surface. Keep the
        // full map for this boundary until the next HTML document is loaded.
        if ($this->isAuthenticationRoute($routeName)) {
            return null;
        }

        if ($request->user()?->isClient()) {
            return ['public', 'portal'];
        }

        if ($this->isPublicRoute($routeName)) {
            return $request->user() ? ['public', 'admin'] : 'public';
        }

        return 'admin';
    }

    private function isAuthenticationRoute(string $routeName): bool
    {
        return in_array($routeName, ['login', 'register', 'logout'], true)
            || Str::startsWith($routeName, [
                'auth.',
                'onboarding.',
                'password.',
                'two-factor.',
                'verification.',
            ]);
    }

    private function isPublicRoute(string $routeName): bool
    {
        return in_array($routeName, [
            'welcome',
            'pricing',
            'terms',
            'privacy',
            'refund',
            'sitemap',
            'favicon',
            'campaigns.track',
            'campaigns.unsubscribe',
        ], true) || Str::startsWith($routeName, ['demo.', 'public.']);
    }
}
