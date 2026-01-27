<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Services\Assistant\OpenAiClient;
use App\Services\Assistant\OpenAiRequestException;
use App\Support\PlatformPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AiImageController extends BaseSuperAdminController
{
    public function generate(Request $request, OpenAiClient $client): JsonResponse
    {
        $this->authorizeCms($request);

        if (!config('services.openai.key')) {
            return response()->json([
                'message' => 'OpenAI n\'est pas configure.',
            ], 422);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:800'],
        ]);

        try {
            $response = $client->generateImage((string) $validated['prompt']);
        } catch (OpenAiRequestException $exception) {
            return response()->json([
                'message' => $exception->userMessage(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Generation d\'image indisponible.',
            ], 500);
        }

        $b64 = $response['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            return response()->json([
                'message' => 'Aucune image retournee.',
            ], 422);
        }

        $binary = base64_decode($b64, true);
        if ($binary === false) {
            return response()->json([
                'message' => 'Image invalide.',
            ], 422);
        }

        $format = strtolower((string) config('services.openai.image_output_format', 'png'));
        $format = preg_replace('/[^a-z0-9]/', '', $format) ?: 'png';
        if (!in_array($format, ['png', 'jpeg', 'jpg', 'webp'], true)) {
            $format = 'png';
        }

        $path = 'cms/ai/' . Str::uuid()->toString() . '.' . $format;
        Storage::disk('public')->put($path, $binary, ['visibility' => 'public']);

        return response()->json([
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    private function authorizeCms(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        if ($user->isSuperadmin()) {
            return;
        }

        if (
            !$user->hasPlatformPermission(PlatformPermissions::WELCOME_MANAGE)
            && !$user->hasPlatformPermission(PlatformPermissions::PAGES_MANAGE)
        ) {
            abort(403);
        }
    }
}
