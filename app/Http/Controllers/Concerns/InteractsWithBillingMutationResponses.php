<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\BillingMutationErrorCode;
use Illuminate\Http\JsonResponse;

trait InteractsWithBillingMutationResponses
{
    protected function billingActionResponse(
        string $status,
        string $action,
        array $payload = [],
        int $httpStatus = 200
    ): JsonResponse {
        return response()->json(array_merge([
            'status' => $status,
            'action' => $action,
        ], $payload), $httpStatus);
    }

    protected function billingErrorResponse(
        string $message,
        BillingMutationErrorCode|string $code,
        int $httpStatus = 422,
        array $payload = []
    ): JsonResponse {
        return response()->json(array_merge([
            'status' => 'error',
            'code' => $this->resolveBillingMutationErrorCode($code),
            'message' => $message,
        ], $payload), $httpStatus);
    }

    protected function billingNoopResponse(
        string $message,
        BillingMutationErrorCode|string $code,
        array $payload = []
    ): JsonResponse {
        return response()->json(array_merge([
            'status' => 'noop',
            'code' => $this->resolveBillingMutationErrorCode($code),
            'message' => $message,
        ], $payload));
    }

    private function resolveBillingMutationErrorCode(BillingMutationErrorCode|string $code): string
    {
        return $code instanceof BillingMutationErrorCode ? $code->value : $code;
    }
}
