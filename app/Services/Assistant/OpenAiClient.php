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
