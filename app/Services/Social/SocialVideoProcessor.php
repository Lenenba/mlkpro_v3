<?php

namespace App\Services\Social;

use App\Models\SocialVideoClip;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class SocialVideoProcessor
{
    /** @return array{duration_ms: int, width: int, height: int} */
    public function inspect(string $path): array
    {
        $result = Process::timeout(20)->run([
            (string) config('social_video.ffprobe'), '-v', 'error',
            '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm',
            '-show_entries', 'format=duration:stream=codec_type,width,height:stream_side_data=rotation',
            '-of', 'json', $path,
        ]);
        if ($result->failed()) {
            throw new RuntimeException('invalid_video');
        }

        $metadata = json_decode($result->output(), true);
        $video = collect($metadata['streams'] ?? [])->firstWhere('codec_type', 'video');
        $duration = (float) ($metadata['format']['duration'] ?? 0);
        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);
        if (! is_finite($duration) || $duration <= 0 || $width < 2 || $height < 2
            || $width > 8192 || $height > 8192 || $width * $height > 33554432) {
            throw new RuntimeException('invalid_video');
        }
        if ($duration * 1000 > (int) config('social_video.max_duration_ms')) {
            throw new RuntimeException('video_too_long');
        }

        $rotation = (int) data_get($video, 'side_data_list.0.rotation', 0);
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        return ['duration_ms' => (int) floor($duration * 1000), 'width' => $width, 'height' => $height];
    }

    public function preview(string $source, string $destination): void
    {
        $this->encode($source, $destination, [
            '-vf', 'scale=trunc(iw*sar/2)*2:ih,setsar=1,scale=960:960:force_original_aspect_ratio=decrease:force_divisible_by=2',
            '-map', '0:v:0', '-map', '0:a:0?',
        ]);
    }

    public function render(string $source, string $destination, SocialVideoClip $clip): void
    {
        $editing = app(SocialVideoEditingService::class);
        $settings = $clip->project?->settings ?? [];
        [$width, $height] = $clip->format === 'portrait' ? [720, 1280] : [1280, 720];
        $squarePixels = 'scale=trunc(iw*sar/2)*2:ih,setsar=1';
        if ($clip->framing === 'blur') {
            $filter = "[0:v:0]{$squarePixels},split[background][foreground];"
                ."[background]scale={$width}:{$height}:force_original_aspect_ratio=increase,"
                ."crop={$width}:{$height},boxblur=20:2[back];"
                ."[foreground]scale={$width}:{$height}:force_original_aspect_ratio=decrease[front];"
                .'[back][front]overlay=(W-w)/2:(H-h)/2,setsar=1[framed]';
        } else {
            $x = $editing->cropExpression($settings['crop_points'] ?? [], 'x', $clip->focal_x, $clip->start_ms);
            $y = $editing->cropExpression($settings['crop_points'] ?? [], 'y', $clip->focal_y, $clip->start_ms);
            $filter = "[0:v:0]{$squarePixels},scale={$width}:{$height}:force_original_aspect_ratio=increase,"
                ."crop={$width}:{$height}:'(iw-ow)*({$x})/100':'(ih-oh)*({$y})/100',setsar=1[framed]";
        }
        $captions = ($settings['captions_enabled'] ?? false)
            ? $editing->clipCaptions($settings['captions'] ?? [], $clip->start_ms, $clip->end_ms) : [];
        $directory = null;
        $inputs = [];
        try {
            if ($captions !== []) {
                $directory = dirname($destination).'/captions-'.Str::uuid();
                File::makeDirectory($directory, 0700, true);
                $timeline = app(SocialVideoCaptionRenderer::class)->timeline($captions, $directory, $width,
                    $clip->end_ms - $clip->start_ms, $settings['caption_style'] ?? 'white');
                $inputs = ['-protocol_whitelist', 'file', '-f', 'concat', '-safe', '1', '-i', $timeline];
                $vertical = ($settings['caption_position'] ?? 'bottom') === 'top' ? 'H*0.06' : 'H-h-H*0.09';
                $filter .= ";[framed][1:v:0]overlay=0:{$vertical}:eof_action=pass[v]";
            } else {
                $filter .= ';[framed]null[v]';
            }
            $options = ['-filter_complex', $filter, '-map', '[v]', '-map', '0:a:0?'];
            $this->encode($source, $destination, $options, $clip->start_ms, $clip->end_ms - $clip->start_ms, $inputs);
        } finally {
            if ($directory !== null) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $inputs
     */
    private function encode(string $source, string $destination, array $options, int $start = 0, ?int $duration = null, array $inputs = []): void
    {
        $command = [
            (string) config('social_video.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm',
            '-threads', '2', '-ss', sprintf('%.3F', $start / 1000), '-i', $source,
        ];
        array_push($command, ...$inputs);
        if ($duration !== null) {
            array_push($command, '-t', sprintf('%.3F', $duration / 1000));
        }
        array_push($command, ...$options);
        array_push($command, '-map_metadata', '-1', '-c:v', 'libx264', '-preset', 'veryfast',
            '-crf', '23', '-pix_fmt', 'yuv420p', '-threads', '2', '-filter_threads', '1',
            '-filter_complex_threads', '1', '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-movflags', '+faststart', '-f', 'mp4', $destination);

        $result = Process::timeout((int) config('social_video.process_timeout'))->run($command);
        clearstatcache(true, $destination);
        if ($result->failed() || ! is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('processing_failed');
        }
    }
}
