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
        $recipient = $this->normalizeRecipient($to);

        if (!$sid || !$token || !$from) {
            return [
                'ok' => false,
                'reason' => 'missing_config',
            ];
        }
        if (!$recipient) {
            return [
                'ok' => false,
                'reason' => 'invalid_recipient',
            ];
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $recipient,
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

    private function normalizeRecipient(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00') && strlen($digits) > 2) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) >= 11) {
            return '+' . $digits;
        }

        return null;
    }
}
