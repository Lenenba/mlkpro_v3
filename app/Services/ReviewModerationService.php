<?php

namespace App\Services;

class ReviewModerationService
{
    public function check(?string $text): array
    {
        if (!$text) {
            return [false, null];
        }

        $normalized = $this->normalize($text);
        $terms = config('reviews.blocked_terms', []);

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $pattern = $this->termPattern($term);
            if (preg_match($pattern, $normalized)) {
                return [true, $term];
            }
        }

        return [false, null];
    }

    private function normalize(string $text): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        return preg_replace('/[^a-z0-9\s]/u', ' ', $lower) ?? $lower;
    }

    private function termPattern(string $term): string
    {
        $escaped = preg_quote($term, '/');
        return '/\b' . $escaped . '\b/u';
    }
}
