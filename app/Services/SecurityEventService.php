<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SecurityEventService
{
    public function record(User $subject, string $action, Request $request, array $extra = []): void
    {
        $userAgent = $request->userAgent();

        $properties = array_filter(array_merge([
            'ip' => $request->ip(),
            'user_agent' => $userAgent ? Str::limit($userAgent, 255, '') : null,
            'channel' => $request->expectsJson() ? 'api' : 'web',
        ], $extra), static fn ($value) => $value !== null && $value !== '');

        ActivityLog::record($subject, $subject, $action, $properties);
    }
}
