<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsappNotificationService
{
    public function send(string $to, string $message): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.whatsapp_from');

        if (!$sid || !$token || !$from) {
            return false;
        }

        $to = $this->normalizeWhatsappNumber($to);
        $from = $this->normalizeWhatsappNumber($from);

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ]);

        return $response->successful();
    }

    private function normalizeWhatsappNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return str_starts_with($value, 'whatsapp:') ? $value : 'whatsapp:' . $value;
    }
}
