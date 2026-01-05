<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register any exception handling callbacks for the application.
     */
    public function register(): void
    {
        //
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception): Response
    {
        if ($this->shouldRedirectOnForbidden($request, $exception)) {
            return $this->buildForbiddenRedirect($request, $exception);
        }

        return parent::render($request, $exception);
    }

    protected function shouldRedirectOnForbidden($request, Throwable $exception): bool
    {
        return $this->isHttpException($exception)
            && $exception->getStatusCode() === 403
            && !$request->expectsJson();
    }

    protected function buildForbiddenRedirect($request, Throwable $exception): Response
    {
        $message = $exception->getMessage() ?: 'Accès refusé.';
        $referer = $request->headers->get('referer');

        if ($referer && $referer !== url()->current()) {
            return redirect()->to($referer)->with('warning', $message);
        }

        $fallback = Route::has('dashboard') ? route('dashboard') : url('/');
        if ($fallback === url()->current()) {
            $fallback = url('/');
        }

        return redirect()->to($fallback)->with('warning', $message);
    }
}
