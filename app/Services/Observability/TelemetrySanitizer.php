<?php

namespace App\Services\Observability;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class TelemetrySanitizer
{
    public function routePattern(?Request $request): ?string
    {
        $uri = $request?->route()?->uri();
        if (! is_string($uri) || trim($uri) === '') {
            return null;
        }

        return '/'.ltrim($uri, '/');
    }

    public function routePatternForName(string $routeName): ?string
    {
        $uri = Route::getRoutes()->getByName($routeName)?->uri();
        if (! is_string($uri) || trim($uri) === '') {
            return null;
        }

        return '/'.ltrim($uri, '/');
    }

    public function queryFingerprint(string $sql): string
    {
        return hash('sha256', $this->normalizedSql($sql));
    }

    public function statementType(string $sql): string
    {
        $normalized = ltrim($this->stripComments($sql));
        if (preg_match('/^([A-Za-z]+)/', $normalized, $matches) !== 1) {
            return 'OTHER';
        }

        $statement = strtoupper($matches[1]);

        return in_array($statement, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'WITH', 'CALL', 'ALTER', 'CREATE', 'DROP'], true)
            ? $statement
            : 'OTHER';
    }

    private function normalizedSql(string $sql): string
    {
        $sql = $this->stripComments($sql);
        $sql = preg_replace("/'(?:''|\\\\.|[^'])*'/s", '?', $sql) ?? '';
        $sql = preg_replace('/"(?:""|\\\\.|[^"])*"/s', '?', $sql) ?? '';
        $sql = preg_replace('/\b0x[0-9a-f]+\b/i', '?', $sql) ?? '';
        $sql = preg_replace('/(?<![A-Za-z0-9_])[-+]?\d+(?:\.\d+)?(?![A-Za-z0-9_])/', '?', $sql) ?? '';

        return strtolower(trim(preg_replace('/\s+/', ' ', $sql) ?? ''));
    }

    private function stripComments(string $sql): string
    {
        $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? '';
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql) ?? '';

        return preg_replace('/#[^\r\n]*/', ' ', $sql) ?? '';
    }
}
