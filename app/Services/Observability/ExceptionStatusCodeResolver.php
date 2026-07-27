<?php

namespace App\Services\Observability;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExceptionStatusCodeResolver
{
    public function resolve(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => $exception->hasStatus()
                ? (int) $exception->status()
                : 403,
            $exception instanceof BackedEnumCaseNotFoundException,
            $exception instanceof ModelNotFoundException,
            $exception instanceof RecordNotFoundException,
            $exception instanceof RecordsNotFoundException => 404,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof RequestExceptionInterface => 400,
            default => 500,
        };
    }
}
