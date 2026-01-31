<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient
{
    public function chat(array $messages, array $options = []): array
    {
        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        $payload = [
            'model' => $options['model'] ?? config('services.openai.model', 'gpt-4o-mini'),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
        ];

        if (($options['json'] ?? true) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if (!$response->ok()) {
            $status = $response->status();
            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $type = is_string($error['type'] ?? null) ? $error['type'] : null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : null;
            throw new OpenAiRequestException($status, $type, $message);
        }

        return $response->json();
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        $apiKey = (string) config('services.openai.key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        $payload = [
            'model' => $options['model'] ?? config('services.openai.image_model', 'gpt-image-1'),
            'prompt' => $prompt,
            'n' => 1,
        ];

        $size = $options['size'] ?? config('services.openai.image_size');
        if (is_string($size)) {
            $size = trim($size);
        }
        $allowedSizes = ['256x256', '512x512', '1024x1024', '1024x1792', '1792x1024'];
        if ($size && in_array($size, $allowedSizes, true)) {
            $payload['size'] = $size;
        }

        $quality = $options['quality'] ?? config('services.openai.image_quality');
        if (is_string($quality)) {
            $quality = strtolower(trim($quality));
        }
        $allowedQualities = ['standard', 'hd', 'low', 'medium', 'high'];
        if ($quality && in_array($quality, $allowedQualities, true)) {
            $payload['quality'] = $quality;
        }

        $background = $options['background'] ?? config('services.openai.image_background');
        if (is_string($background)) {
            $background = strtolower(trim($background));
        }
        $allowedBackgrounds = ['transparent', 'opaque'];
        if ($background && in_array($background, $allowedBackgrounds, true)) {
            $payload['background'] = $background;
        }

        $outputFormat = $options['output_format'] ?? config('services.openai.image_output_format');
        if (is_string($outputFormat)) {
            $outputFormat = strtolower(trim($outputFormat));
        }
        if ($outputFormat === 'jpg') {
            $outputFormat = 'jpeg';
        }
        $allowedFormats = ['png', 'jpeg', 'webp'];
        if ($outputFormat && in_array($outputFormat, $allowedFormats, true)) {
            $payload['output_format'] = $outputFormat;
        }

        $timeout = (int) ($options['timeout'] ?? 60);

        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->post('https://api.openai.com/v1/images/generations', $payload);

        if (!$response->ok()) {
            $status = $response->status();
            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $type = is_string($error['type'] ?? null) ? $error['type'] : null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : null;
            throw new OpenAiRequestException($status, $type, $message);
        }

        return $response->json();
    }

    public function extractMessage(array $response): string
    {
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }

    public function extractUsage(array $response): array
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'model' => $response['model'] ?? null,
        ];
    }
}
