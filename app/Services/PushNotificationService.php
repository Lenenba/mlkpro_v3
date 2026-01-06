<?php

namespace App\Services;

use App\Models\UserPushToken;
use Illuminate\Support\Facades\Http;

class PushNotificationService
{
    public function sendToUsers(array $userIds, array $payload): int
    {
        $userIds = array_values(array_filter(array_unique($userIds)));
        if (!$userIds) {
            return 0;
        }

        $tokens = UserPushToken::query()
            ->whereIn('user_id', $userIds)
            ->pluck('token')
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return 0;
        }

        $messages = $tokens->map(function (string $token) use ($payload) {
            return [
                'to' => $token,
                'sound' => 'default',
                'title' => $payload['title'] ?? '',
                'body' => $payload['body'] ?? '',
                'data' => $payload['data'] ?? [],
            ];
        })->all();

        $count = 0;
        foreach (array_chunk($messages, 100) as $chunk) {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post('https://exp.host/--/api/v2/push/send', $chunk);

            if ($response->successful()) {
                $count += count($chunk);
            }
        }

        return $count;
    }
}
