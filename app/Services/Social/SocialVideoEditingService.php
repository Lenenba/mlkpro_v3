<?php

namespace App\Services\Social;

class SocialVideoEditingService
{
    /** @param array<string, mixed> $settings */
    public function validate(array $settings, int $duration): void
    {
        $previousEnd = 0;
        foreach ($settings['captions'] ?? [] as $caption) {
            if ($caption['start_ms'] < $previousEnd || $caption['end_ms'] <= $caption['start_ms']
                || $caption['end_ms'] > $duration || trim($caption['text']) === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(['captions' => __('social_video.invalid_captions')]);
            }
            $previousEnd = $caption['end_ms'];
        }
        $previousTime = -1;
        foreach ($settings['crop_points'] ?? [] as $point) {
            if ($point['time_ms'] <= $previousTime || $point['time_ms'] > $duration) {
                throw \Illuminate\Validation\ValidationException::withMessages(['crop_points' => __('social_video.invalid_crop_points')]);
            }
            $previousTime = $point['time_ms'];
        }
        if (($settings['captions_enabled'] ?? false) && ($settings['captions'] ?? []) !== []
            && ! app(SocialVideoCaptionRenderer::class)->available()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['captions' => __('social_video.captions_unavailable')]);
        }
    }

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $captions
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     */
    public function clipCaptions(array $captions, int $start, int $end): array
    {
        $result = [];
        foreach ($captions as $caption) {
            if ($caption['end_ms'] > $start && $caption['start_ms'] < $end) {
                $result[] = [
                    'start_ms' => max(0, $caption['start_ms'] - $start),
                    'end_ms' => min($end, $caption['end_ms']) - $start,
                    'text' => $caption['text'],
                ];
            }
        }

        return $result;
    }

    /** @param list<array{time_ms: int, x: int, y: int}> $points */
    public function cropExpression(array $points, string $axis, int $fallback, int $start): string
    {
        if ($points === []) {
            return (string) $fallback;
        }
        $expression = (string) (int) $points[0][$axis];
        foreach (array_slice($points, 1) as $index => $point) {
            $previous = $points[$index];
            $delta = (int) $point[$axis] - (int) $previous[$axis];
            $offset = sprintf('%.3F', ($start - $previous['time_ms']) / 1000);
            $duration = sprintf('%.3F', ($point['time_ms'] - $previous['time_ms']) / 1000);
            $expression .= "+({$delta})*clip((t+({$offset}))/{$duration},0,1)";
        }

        return $expression;
    }
}
