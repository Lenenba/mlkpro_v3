<?php

namespace App\Support;

class PlanDisplay
{
    public static function normalize(array $plans, array $overrides): array
    {
        $payload = [];
        foreach ($plans as $key => $plan) {
            $payload[$key] = self::merge($plan, $key, $overrides);
        }

        return $payload;
    }

    public static function merge(array $plan, string $key, array $overrides): array
    {
        $display = $overrides[$key] ?? [];
        $name = self::cleanString($display['name'] ?? '');
        $badge = self::cleanString($display['badge'] ?? '');
        $price = array_key_exists('price', $display) ? $display['price'] : ($plan['price'] ?? null);
        $features = $display['features'] ?? null;
        if (!is_array($features)) {
            $features = $plan['features'] ?? [];
        }

        return [
            'name' => $name !== '' ? $name : ($plan['name'] ?? ucfirst($key)),
            'price' => $price,
            'badge' => $badge !== '' ? $badge : null,
            'features' => self::cleanFeatures($features),
        ];
    }

    private static function cleanString($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function cleanFeatures($features): array
    {
        if (!is_array($features)) {
            return [];
        }

        return collect($features)
            ->map(fn ($feature) => is_string($feature) ? trim($feature) : '')
            ->filter()
            ->values()
            ->all();
    }
}
