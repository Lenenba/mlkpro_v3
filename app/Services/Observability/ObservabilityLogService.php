<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Log;

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
        if (! config('observability.enabled', true)) {
            return;
        }

        $payload = array_merge([
            'event' => $event,
            'environment' => config('app.env'),
            'app' => config('app.name'),
        ], $context);

        Log::channel((string) config('observability.log_channel', 'observability'))
            ->{$level}($event, $payload);
    }
}
