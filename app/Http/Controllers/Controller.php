<?php

namespace App\Http\Controllers;

use App\Support\DataTablePagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function dataTablePerPageOptions(): array
    {
        return DataTablePagination::options();
    }

    protected function defaultDataTablePerPage(): int
    {
        return DataTablePagination::defaultPerPage();
    }

    protected function resolveDataTablePerPage(mixed $requestOrValue = null, ?int $default = null): int
    {
        $default ??= $this->defaultDataTablePerPage();

        if ($requestOrValue instanceof Request || $requestOrValue === null) {
            return DataTablePagination::fromRequest($requestOrValue, $default);
        }

        return DataTablePagination::resolve($requestOrValue, $default);
    }

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
