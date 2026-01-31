<?php

namespace App\Http\Controllers;

use App\Services\AiImageUsageService;
use App\Services\Assistant\OpenAiClient;
use App\Services\Assistant\OpenAiRequestException;
use App\Services\AssistantCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class AiImageController extends Controller
{
    public function generate(
        Request $request,
        OpenAiClient $client,
        AiImageUsageService $usageService,
        AssistantCreditService $creditService
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        @ini_set('max_execution_time', '120');

        if (!config('services.openai.key')) {
            return response()->json([
                'message' => 'OpenAI n\'est pas configure.',
            ], 422);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:800'],
            'context' => ['required', 'string', Rule::in($usageService->contexts())],
        ]);

        $context = (string) $validated['context'];
        $owner = $usageService->resolveOwner($user);
        $limit = AiImageUsageService::FREE_DAILY_LIMIT;

        $usedFree = false;
        $creditConsumed = false;

        if ($usageService->remaining($owner, $context, $limit) > 0) {
            $usedFree = true;
        } else {
            $creditConsumed = $usageService->consumeCredit($owner, $context, 1);
            if (!$creditConsumed) {
                return response()->json([
                    'message' => 'Limite quotidienne d\'images IA atteinte. Achetez un pack IA pour continuer.',
                ], 429);
            }
        }

        try {
            $timeout = (int) config('services.openai.image_timeout', 120);
            $response = $client->generateImage((string) $validated['prompt'], [
                'timeout' => $timeout > 0 ? $timeout : 120,
            ]);
        } catch (OpenAiRequestException $exception) {
            if ($creditConsumed) {
                $creditService->refund($owner, 1, [
                    'source' => $usageService->sourceForContext($context),
                    'meta' => ['context' => $context, 'reason' => 'openai_failed'],
                ]);
            }

            return response()->json([
                'message' => $exception->userMessage(),
            ], 422);
        } catch (Throwable $exception) {
            if ($creditConsumed) {
                $creditService->refund($owner, 1, [
                    'source' => $usageService->sourceForContext($context),
                    'meta' => ['context' => $context, 'reason' => 'runtime_failed'],
                ]);
            }

            return response()->json([
                'message' => 'Generation d\'image indisponible.',
            ], 500);
        }

        $b64 = $response['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            if ($creditConsumed) {
                $creditService->refund($owner, 1, [
                    'source' => $usageService->sourceForContext($context),
                    'meta' => ['context' => $context, 'reason' => 'empty_response'],
                ]);
            }

            return response()->json([
                'message' => 'Aucune image retournee.',
            ], 422);
        }

        $binary = base64_decode($b64, true);
        if ($binary === false) {
            if ($creditConsumed) {
                $creditService->refund($owner, 1, [
                    'source' => $usageService->sourceForContext($context),
                    'meta' => ['context' => $context, 'reason' => 'invalid_image'],
                ]);
            }

            return response()->json([
                'message' => 'Image invalide.',
            ], 422);
        }

        $format = strtolower((string) config('services.openai.image_output_format', 'png'));
        $format = preg_replace('/[^a-z0-9]/', '', $format) ?: 'png';
        if (!in_array($format, ['png', 'jpeg', 'jpg', 'webp'], true)) {
            $format = 'png';
        }

        $path = sprintf(
            'company/ai/%d/%s-%s.%s',
            $owner->id,
            $context,
            Str::uuid()->toString(),
            $format
        );
        try {
            Storage::disk('public')->put($path, $binary, ['visibility' => 'public']);
        } catch (Throwable $exception) {
            if ($creditConsumed) {
                $creditService->refund($owner, 1, [
                    'source' => $usageService->sourceForContext($context),
                    'meta' => ['context' => $context, 'reason' => 'storage_failed'],
                ]);
            }

            return response()->json([
                'message' => 'Generation d\'image indisponible.',
            ], 500);
        }

        if ($usedFree) {
            $usageService->recordFree($owner, $context);
        }

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'mode' => $usedFree ? 'free' : 'credit',
            'remaining' => $usageService->remaining($owner, $context, $limit),
            'credit_balance' => $usageService->creditBalance($owner),
        ]);
    }
}
