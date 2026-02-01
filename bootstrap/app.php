<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureTwoFactorVerified::class,
            \App\Http\Middleware\EnsureOnboardingIsComplete::class,
            \App\Http\Middleware\EnsureNotSuspended::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->throttleApi();

        $middleware->alias([
            'company.feature' => \App\Http\Middleware\EnsureCompanyFeature::class,
            'impersonating' => \App\Http\Middleware\EnsureImpersonating::class,
            'demo.safe' => \App\Http\Middleware\EnsureDemoSafeMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $redirectForbidden = function (Request $request, ?string $message = null) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            $fallbackMessage = 'Acces refuse. Vous n\'avez pas les permissions necessaires.';
            $message = $message && !in_array($message, ['This action is unauthorized.', 'Forbidden'], true)
                ? $message
                : $fallbackMessage;

            $previous = url()->previous();
            $current = $request->fullUrl();
            $fallback = route('dashboard');
            $target = $previous && $previous !== $current ? $previous : $fallback;

            return redirect()->to($target)->with('warning', $message);
        };

        $exceptions->renderable(function (AuthorizationException $exception, Request $request) use ($redirectForbidden) {
            return $redirectForbidden($request, $exception->getMessage());
        });

        $exceptions->renderable(function (HttpExceptionInterface $exception, Request $request) use ($redirectForbidden) {
            if ($exception->getStatusCode() !== 403) {
                return null;
            }

            return $redirectForbidden($request, $exception->getMessage());
        });
    })->create();
