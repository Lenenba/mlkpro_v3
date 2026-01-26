<?php

namespace App\Services;

use App\Models\TrackingEvent;
use Illuminate\Http\Request;

class TrackingService
{
    public function record(string $eventType, ?int $userId = null, array $meta = []): void
    {
        $request = request();
        if (!$request instanceof Request) {
            return;
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $ipHash = $ip ? hash('sha256', $ip) : null;
        $visitorHash = ($ip && $userAgent)
            ? hash('sha256', $ip . '|' . $userAgent)
            : $ipHash;

        TrackingEvent::create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip_hash' => $ipHash,
            'visitor_hash' => $visitorHash,
            'user_agent' => $userAgent,
            'meta' => $meta ?: null,
        ]);
    }
}
