<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!config('security.headers.enabled', true)) {
            return $response;
        }

        if (!$response instanceof Response) {
            return $response;
        }

        $headers = $response->headers;

        $setIfMissing = static function (string $name, ?string $value) use ($headers): void {
            if (!$value || $headers->has($name)) {
                return;
            }

            $headers->set($name, $value);
        };

        $setIfMissing('X-Frame-Options', config('security.headers.x_frame_options', 'SAMEORIGIN'));
        $setIfMissing('X-Content-Type-Options', config('security.headers.x_content_type_options', 'nosniff'));
        $setIfMissing('Referrer-Policy', config('security.headers.referrer_policy', 'strict-origin-when-cross-origin'));
        $setIfMissing('Permissions-Policy', config('security.headers.permissions_policy'));

        $csp = config('security.headers.csp');
        if ($csp && !$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $csp);
        }

        if (config('security.headers.hsts.enabled', false) && $request->isSecure()) {
            $maxAge = (int) config('security.headers.hsts.max_age', 31536000);
            $hsts = 'max-age=' . max(0, $maxAge);

            if (config('security.headers.hsts.include_subdomains', true)) {
                $hsts .= '; includeSubDomains';
            }

            if (config('security.headers.hsts.preload', false)) {
                $hsts .= '; preload';
            }

            $setIfMissing('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
