<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\User;
use App\Services\AiImageUsageService;
use App\Services\Assistant\OpenAiClient;
use App\Services\Assistant\OpenAiRequestException;
use App\Services\AssistantCreditService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SocialAiImageGenerationService
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly AiImageUsageService $usageService,
        private readonly AssistantCreditService $creditService,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function generateIfNeeded(User $owner, SocialAutomationRule $rule, array $settings, array $context): ?array
    {
        if (! $this->booleanValue($settings['image_ai_enabled'] ?? false)) {
            return null;
        }

        $imageMode = $this->allowedString(
            $settings['image_mode'] ?? null,
            SocialAutomationRule::allowedAiImageModes(),
            SocialAutomationRule::AI_IMAGE_MODE_IF_MISSING
        );
        $sourceImageUrl = trim((string) ($context['source_image_url'] ?? ''));

        if ($imageMode === SocialAutomationRule::AI_IMAGE_MODE_NEVER) {
            return $this->skipped('image_mode_never', 'AI image generation is disabled by this rule image mode.', $settings, $context);
        }

        if ($imageMode === SocialAutomationRule::AI_IMAGE_MODE_IF_MISSING && $sourceImageUrl !== '') {
            return $this->skipped('source_image_present', 'The selected source already provides an image.', $settings, $context);
        }

        $prompt = $this->imagePrompt($owner, $settings, $context);
        if ($prompt === '') {
            return $this->skipped('image_prompt_missing', 'Pulse could not build an AI image prompt for this source.', $settings, $context);
        }

        if (! config('services.openai.key')) {
            return $this->failed('openai_not_configured', 'OpenAI is not configured for Pulse image generation.', $prompt, $settings, $context);
        }

        $usedFree = false;
        $creditConsumed = false;
        $contextKey = AiImageUsageService::CONTEXT_SOCIAL;
        $limit = AiImageUsageService::FREE_DAILY_LIMIT;

        if ($this->usageService->remaining($owner, $contextKey, $limit) > 0) {
            $usedFree = true;
        } else {
            $creditConsumed = $this->usageService->consumeCredit($owner, $contextKey, 1);
            if (! $creditConsumed) {
                return $this->failed('credits_exhausted', 'AI image quota is exhausted for this workspace.', $prompt, $settings, $context);
            }
        }

        $format = $this->outputFormat();
        $size = $this->sizeForFormat((string) ($settings['image_format'] ?? SocialAutomationRule::AI_IMAGE_FORMAT_SQUARE));

        try {
            $timeout = (int) config('services.openai.image_timeout', 120);
            $response = $this->client->generateImage($prompt, [
                'timeout' => $timeout > 0 ? $timeout : 120,
                'size' => $size,
            ]);

            $binary = $this->imageBinary($response);
            $path = sprintf(
                'company/ai/%d/social-%s.%s',
                $owner->id,
                Str::uuid()->toString(),
                $format
            );

            Storage::disk('public')->put($path, $binary, ['visibility' => 'public']);

            if ($usedFree) {
                $this->usageService->recordFree($owner, $contextKey);
            }

            $url = Storage::disk('public')->url($path);
            $model = (string) config('services.openai.image_model', 'gpt-image-1');

            return [
                'generated' => true,
                'status' => 'generated',
                'outcome_code' => $usedFree ? 'generated_free' : 'generated_credit',
                'model' => $model,
                'prompt' => $prompt,
                'image_mode' => $imageMode,
                'image_format' => $this->allowedString(
                    $settings['image_format'] ?? null,
                    SocialAutomationRule::allowedAiImageFormats(),
                    SocialAutomationRule::AI_IMAGE_FORMAT_SQUARE
                ),
                'size' => $size,
                'usage_mode' => $usedFree ? 'free' : 'credit',
                'credit_balance' => $this->usageService->creditBalance($owner),
                'fallback_used' => false,
                'fallback_reason' => null,
                'media_payload' => [[
                    'type' => 'image',
                    'url' => $url,
                    'disk' => 'public',
                    'path' => $path,
                    'source' => 'ai',
                    'name' => basename($path),
                    'mime_type' => $this->mimeType($format),
                    'size' => strlen($binary),
                ]],
            ];
        } catch (OpenAiRequestException $exception) {
            $this->refundIfNeeded($owner, $creditConsumed, $contextKey, 'openai_failed');

            Log::warning('Pulse AI image generation failed.', [
                'user_id' => $owner->id,
                'rule_id' => $rule->id,
                'status' => $exception->status(),
                'type' => $exception->type(),
                'api_message' => $exception->apiMessage(),
            ]);

            return $this->failed('openai_failed', $exception->userMessage(), $prompt, $settings, $context);
        } catch (Throwable $exception) {
            $this->refundIfNeeded($owner, $creditConsumed, $contextKey, 'runtime_failed');

            Log::warning('Pulse AI image generation fell back without an image.', [
                'user_id' => $owner->id,
                'rule_id' => $rule->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->failed('runtime_failed', 'AI image generation failed for this Pulse candidate.', $prompt, $settings, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function skipped(string $outcomeCode, string $reason, array $settings, array $context): array
    {
        return [
            'generated' => false,
            'status' => 'skipped',
            'outcome_code' => $outcomeCode,
            'model' => null,
            'prompt' => $this->imagePrompt(null, $settings, $context),
            'image_mode' => $this->allowedString(
                $settings['image_mode'] ?? null,
                SocialAutomationRule::allowedAiImageModes(),
                SocialAutomationRule::AI_IMAGE_MODE_IF_MISSING
            ),
            'image_format' => $this->allowedString(
                $settings['image_format'] ?? null,
                SocialAutomationRule::allowedAiImageFormats(),
                SocialAutomationRule::AI_IMAGE_FORMAT_SQUARE
            ),
            'fallback_used' => false,
            'fallback_reason' => $reason,
            'media_payload' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function failed(string $outcomeCode, string $reason, string $prompt, array $settings, array $context): array
    {
        return [
            'generated' => false,
            'status' => 'failed',
            'outcome_code' => $outcomeCode,
            'model' => null,
            'prompt' => $prompt,
            'image_mode' => $this->allowedString(
                $settings['image_mode'] ?? null,
                SocialAutomationRule::allowedAiImageModes(),
                SocialAutomationRule::AI_IMAGE_MODE_IF_MISSING
            ),
            'image_format' => $this->allowedString(
                $settings['image_format'] ?? null,
                SocialAutomationRule::allowedAiImageFormats(),
                SocialAutomationRule::AI_IMAGE_FORMAT_SQUARE
            ),
            'fallback_used' => true,
            'fallback_reason' => Str::limit(trim($reason), 240, ''),
            'media_payload' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $context
     */
    private function imagePrompt(?User $owner, array $settings, array $context): string
    {
        $creativePrompt = trim((string) ($context['selected_image_prompt'] ?? ''));
        $rulePrompt = trim((string) ($settings['image_prompt'] ?? ''));
        $sourceLabel = trim((string) ($context['source_label'] ?? ''));
        $sourceSummary = trim((string) ($context['source_summary'] ?? ''));
        $companyName = $owner instanceof User
            ? trim((string) ($owner->company_name ?: $owner->name ?: config('app.name')))
            : trim((string) ($context['company_name'] ?? config('app.name')));
        $format = $this->allowedString(
            $settings['image_format'] ?? null,
            SocialAutomationRule::allowedAiImageFormats(),
            SocialAutomationRule::AI_IMAGE_FORMAT_SQUARE
        );

        return Str::limit(trim(implode(' ', array_filter([
            $creativePrompt,
            $rulePrompt,
            $sourceLabel !== '' ? 'Subject: '.$sourceLabel.'.' : null,
            $sourceSummary !== '' ? 'Context: '.Str::limit($sourceSummary, 220, '') : null,
            $companyName !== '' ? 'Brand context: '.$companyName.'.' : null,
            'Create a realistic social media image for a small business.',
            'Format: '.$format.'.',
            'No embedded text, no logos, no watermarks, no UI screenshots.',
        ]))), 900, '');
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function imageBinary(array $response): string
    {
        $b64 = $response['data'][0]['b64_json'] ?? null;
        if (! is_string($b64) || trim($b64) === '') {
            throw new RuntimeException('OpenAI returned no image data.');
        }

        $binary = base64_decode($b64, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('OpenAI returned invalid image data.');
        }

        return $binary;
    }

    private function refundIfNeeded(User $owner, bool $creditConsumed, string $context, string $reason): void
    {
        if (! $creditConsumed) {
            return;
        }

        $this->creditService->refund($owner, 1, [
            'source' => $this->usageService->sourceForContext($context),
            'meta' => [
                'context' => $context,
                'reason' => $reason,
            ],
        ]);
    }

    private function outputFormat(): string
    {
        $format = strtolower((string) config('services.openai.image_output_format', 'png'));
        $format = preg_replace('/[^a-z0-9]/', '', $format) ?: 'png';
        if ($format === 'jpg') {
            return 'jpeg';
        }

        return in_array($format, ['png', 'jpeg', 'webp'], true) ? $format : 'png';
    }

    private function mimeType(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function sizeForFormat(string $format): string
    {
        return match ($format) {
            SocialAutomationRule::AI_IMAGE_FORMAT_PORTRAIT => '1024x1792',
            SocialAutomationRule::AI_IMAGE_FORMAT_LANDSCAPE => '1792x1024',
            default => '1024x1024',
        };
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedString(mixed $value, array $allowed, string $fallback): string
    {
        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered ?? (bool) $value;
    }
}
