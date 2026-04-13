<?php

namespace App\Support;

use Illuminate\Http\Request;

class DataTablePagination
{
    private const OPTIONS = [5, 10, 25, 50, 100];

    public static function options(): array
    {
        return self::OPTIONS;
    }

    public static function defaultPerPage(): int
    {
        return 10;
    }

    public static function resolve(mixed $value, ?int $default = null): int
    {
        $default = self::normalizeDefault($default);
        $candidate = (int) $value;

        return in_array($candidate, self::OPTIONS, true)
            ? $candidate
            : $default;
    }

    public static function fromRequest(?Request $request = null, ?int $default = null): int
    {
        $request = $request ?? request();

        return self::resolve($request->query('per_page'), $default);
    }

    private static function normalizeDefault(?int $default = null): int
    {
        $default = (int) ($default ?? self::defaultPerPage());

        return in_array($default, self::OPTIONS, true)
            ? $default
            : self::defaultPerPage();
    }
}
