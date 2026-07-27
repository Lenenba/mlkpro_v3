<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

class ObservabilityLogService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $event, array $context): void
    {
        if (! config('observability.enabled', false)) {
            return;
        }

        $payload = array_merge([
            'event' => $event,
            'environment' => config('app.env'),
            'app' => config('app.name'),
        ], $context);

        try {
            Log::channel((string) config('observability.log_channel', 'observability'))
                ->{$level}($event, $payload);
        } catch (Throwable) {
            // Observability must never turn a successful business action into an error.
        }
    }
}
