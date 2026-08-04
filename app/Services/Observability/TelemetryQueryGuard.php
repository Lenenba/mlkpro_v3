<?php

namespace App\Services\Observability;

use Closure;
use Illuminate\Http\Request;
use WeakMap;

class TelemetryQueryGuard
{
    /** @var WeakMap<Request, int> */
    private WeakMap $requestDepth;

    private int $fallbackDepth = 0;

    public function __construct()
    {
        $this->requestDepth = new WeakMap;
    }

    public function active(): bool
    {
        $request = $this->request();

        return $request === null
            ? $this->fallbackDepth > 0
            : ($this->requestDepth[$request] ?? 0) > 0;
    }

    public function run(Closure $callback): mixed
    {
        $request = $this->request();
        if ($request === null) {
            $this->fallbackDepth++;
        } else {
            $this->requestDepth[$request] = ($this->requestDepth[$request] ?? 0) + 1;
        }

        try {
            return $callback();
        } finally {
            if ($request === null) {
                $this->fallbackDepth = max(0, $this->fallbackDepth - 1);
            } else {
                $depth = ($this->requestDepth[$request] ?? 1) - 1;
                if ($depth <= 0) {
                    unset($this->requestDepth[$request]);
                } else {
                    $this->requestDepth[$request] = $depth;
                }
            }
        }
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request instanceof Request ? $request : null;
    }
}
