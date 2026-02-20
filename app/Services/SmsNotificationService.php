<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SmsNotificationService
{
    public function send(string $to, string $message): bool
    {
        $result = $this->sendWithResult($to, $message);

        return (bool) ($result['ok'] ?? false);
    }

    public function sendWithResult(string $to, string $message): array
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        if (!$sid || !$token || !$from) {
            return [
                'ok' => false,
                'reason' => 'missing_config',
            ];
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $message,
                ]);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'reason' => 'http_exception',
                'error' => $exception->getMessage(),
            ];
        }

        if ($response->successful()) {
            return [
                'ok' => true,
                'status' => $response->status(),
                'sid' => $response->json('sid'),
            ];
        }

        return [
            'ok' => false,
            'reason' => 'twilio_error',
            'status' => $response->status(),
            'code' => $response->json('code'),
            'message' => $response->json('message'),
            'more_info' => $response->json('more_info'),
        ];
    }
}
