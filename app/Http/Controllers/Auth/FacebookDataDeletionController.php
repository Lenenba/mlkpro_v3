<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\FacebookDataDeletionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class FacebookDataDeletionController extends Controller
{
    public function callback(Request $request, FacebookDataDeletionService $deletionService): JsonResponse
    {
        $validated = $request->validate([
            'signed_request' => ['required', 'string'],
        ]);

        try {
            $deletionRequest = $deletionService->handleCallback((string) $validated['signed_request']);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to process the Facebook data deletion request right now.',
            ], 500);
        }

        return response()->json([
            'url' => route('integrations.facebook.data-deletion.status', [
                'confirmationCode' => $deletionRequest->confirmation_code,
            ]),
            'confirmation_code' => $deletionRequest->confirmation_code,
        ]);
    }

    public function status(
        Request $request,
        string $confirmationCode,
        FacebookDataDeletionService $deletionService
    ): Response|JsonResponse {
        try {
            $deletionRequest = $deletionService->findByConfirmationCode($confirmationCode);
        } catch (ModelNotFoundException) {
            abort(404);
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'provider' => $deletionRequest->provider,
                'confirmation_code' => $deletionRequest->confirmation_code,
                'status' => $deletionRequest->status,
                'delete_local_account' => $deletionRequest->delete_local_account,
                'summary' => $deletionRequest->summary,
                'requested_at' => $deletionRequest->requested_at?->toIso8601String(),
                'completed_at' => $deletionRequest->completed_at?->toIso8601String(),
            ]);
        }

        return response()->view('auth.facebook-data-deletion-status', [
            'deletionRequest' => $deletionRequest,
        ]);
    }
}
