<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsappNotificationService
{
    public function send(string $to, string $message): bool
    {
        $result = $this->sendWithResult($to, $message);

        return (bool) ($result['ok'] ?? false);
    }

    public function sendWithResult(string $to, string $message): array
    {
        $sid = trim((string) config('services.twilio.sid'));
        $token = trim((string) config('services.twilio.token'));
        $from = trim((string) config('services.twilio.whatsapp_from'));

        if ($sid === '' || $token === '' || $from === '') {
            return [
                'ok' => false,
                'reason' => 'missing_config',
            ];
        }

        $recipient = $this->normalizeWhatsappNumber($to);
        $sender = $this->normalizeWhatsappNumber($from);
        if ($recipient === null) {
            return [
                'ok' => false,
                'reason' => 'invalid_recipient',
            ];
        }
        if ($sender === null) {
            return [
                'ok' => false,
                'reason' => 'invalid_sender',
            ];
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $recipient,
                    'From' => $sender,
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

    private function normalizeWhatsappNumber(string $value): ?string
    {
        $raw = preg_replace('/^whatsapp:/i', '', trim($value)) ?? '';
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if (str_starts_with($digits, '00') && strlen($digits) > 2) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }
        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        return 'whatsapp:+'.$digits;
    }
}
