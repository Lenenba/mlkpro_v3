<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function inertiaOrJson(string $component, array $props)
    {
        if ($this->shouldReturnJson()) {
            return response()->json($props);
        }

        return inertia($component, $props);
    }

    protected function shouldReturnJson(?Request $request = null): bool
    {
        $request = $request ?? request();

        if ($request->is('api/*')) {
            return true;
        }

        return $request->expectsJson() && !$request->headers->has('X-Inertia');
    }
}
