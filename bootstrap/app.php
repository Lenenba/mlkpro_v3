<?php

use App\Services\Observability\ErrorMetricsService;
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
        $middleware->web(prepend: [
            \App\Http\Middleware\RecordRequestMetrics::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\NormalizePublicSeoUrls::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\EnsureClientPortalAccess::class,
            \App\Http\Middleware\EnsureTwoFactorVerified::class,
            \App\Http\Middleware\EnsureOnboardingIsComplete::class,
            \App\Http\Middleware\EnsureNotSuspended::class,
            \App\Http\Middleware\EnsureDemoWorkspaceNotExpired::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\RecordRequestMetrics::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->throttleApi();
        $middleware->validateCsrfTokens(except: [
            'integrations/facebook/data-deletion',
        ]);
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Routing\Middleware\ValidateSignature::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\ValidateSignature::class,
            \App\Http\Middleware\SetLocale::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\ValidateSignature::class,
            \App\Http\Middleware\SecurityHeaders::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\ValidateSignature::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureClientPortalAccess::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureClientUser::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureNotSuspended::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsurePortalCapability::class,
        );

        $middleware->alias([
            'company.feature' => \App\Http\Middleware\EnsureCompanyFeature::class,
            'permission' => \App\Http\Middleware\EnsureCompanyPermission::class,
            'impersonating' => \App\Http\Middleware\EnsureImpersonating::class,
            'demo.safe' => \App\Http\Middleware\EnsureDemoSafeMode::class,
            'not.superadmin' => \App\Http\Middleware\EnsureNotSuperadmin::class,
            'portal.capability' => \App\Http\Middleware\EnsurePortalCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (\Throwable $exception): void {
            try {
                app(ErrorMetricsService::class)->record(
                    $exception,
                    app()->bound('request') ? request() : null
                );
            } catch (\Throwable) {
                // Keep console/bootstrap exceptions reportable even before facades are fully ready.
            }
        });

        $redirectForbidden = function (Request $request, ?string $message = null) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            $fallbackMessage = 'Acces refuse. Vous n\'avez pas les permissions necessaires.';
            $message = $message && ! in_array($message, ['This action is unauthorized.', 'Forbidden'], true)
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
