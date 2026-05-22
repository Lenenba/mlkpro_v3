<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizePublicSeoUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((! $request->isMethod('GET') && ! $request->isMethod('HEAD')) || $request->expectsJson()) {
            return $next($request);
        }

        $queryString = $request->getQueryString();

        if ($queryString && $this->containsOnlyEmptyNoiseParameters($request)) {
            return redirect()->to($request->url(), 301);
        }

        return $next($request);
    }

    private function containsOnlyEmptyNoiseParameters(Request $request): bool
    {
        $parameters = $request->query->all();

        if ($parameters === []) {
            return false;
        }

        foreach ($parameters as $key => $value) {
            if (! $this->isEmptyNoiseParameter((string) $key, $value)) {
                return false;
            }
        }

        return true;
    }

    private function isEmptyNoiseParameter(string $key, mixed $value): bool
    {
        $normalizedKey = trim(rawurldecode($key));
        $isEmptyValue = $value === null || $value === '' || $value === [];

        return $isEmptyValue && ($normalizedKey === '' || preg_match('/^[^A-Za-z0-9_]+$/', $normalizedKey) === 1);
    }
}
