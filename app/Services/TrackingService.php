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

        $utm = $this->extractUtmParams($request);
        $referrerHost = $this->extractReferrerHost($request->headers->get('referer'));
        if ($referrerHost) {
            $utm['referrer_host'] = $referrerHost;
        }

        TrackingEvent::create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip_hash' => $ipHash,
            'visitor_hash' => $visitorHash,
            'user_agent' => $userAgent,
            'meta' => array_filter(array_merge($utm, $meta), fn ($value) => $value !== null && $value !== '') ?: null,
        ]);
    }

    private function extractUtmParams(Request $request): array
    {
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $values = [];

        foreach ($keys as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function extractReferrerHost(?string $referrer): ?string
    {
        if (!$referrer) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        return $host ? strtolower($host) : null;
    }
}
