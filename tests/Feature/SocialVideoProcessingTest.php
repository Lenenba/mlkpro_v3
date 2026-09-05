<?php

use App\Jobs\PrepareSocialVideoJob;
use App\Jobs\RenderSocialVideoClipJob;
use App\Models\SocialVideoClip;
use App\Models\SocialVideoProject;
use App\Services\Social\SocialVideoProcessor;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

it('marks invalid sources as failed and keeps the original for recovery', function () {
    Storage::fake('local');
    $project = SocialVideoProject::factory()->create(['status' => 'pending']);
    Storage::disk('local')->put($project->source_path, 'invalid');
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(fn () => (new PrepareSocialVideoJob($project->id))->handle(app(SocialVideoProcessor::class)))
        ->toThrow(RuntimeException::class, 'invalid_video');
    expect($project->refresh()->status)->toBe('failed');
    expect($project->error_code)->toBe('invalid_video');
    Storage::disk('local')->assertExists($project->source_path);
    Process::assertRan(fn ($process) => in_array('-format_whitelist', $process->command, true));
});

it('rejects videos longer than thirty minutes before encoding', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'format' => ['duration' => '1800.1'],
        'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080]],
    ]))]);
    expect(fn () => app(SocialVideoProcessor::class)->inspect('/test/input.mp4'))
        ->toThrow(RuntimeException::class, 'video_too_long');
    Process::assertRanTimes(fn ($process) => $process->command[0] === config('social_video.ffprobe'), 1);
});

it('marks a failed clip without exposing process output and ignores duplicate jobs', function () {
    Storage::fake('local');
    $clip = SocialVideoClip::factory()->create();
    Process::fake(['*' => Process::result(errorOutput: 'private-path secret-output', exitCode: 1)]);
    $job = new RenderSocialVideoClipJob($clip->id);

    expect(fn () => $job->handle(app(SocialVideoProcessor::class)))->toThrow(RuntimeException::class, 'processing_failed');
    expect($clip->refresh()->status)->toBe('failed');
    expect($clip->error_code)->toBe('processing_failed');
    $job->handle(app(SocialVideoProcessor::class));
    Process::assertRanTimes(fn ($process) => $process->command[0] === config('social_video.ffmpeg'), 1);
});

it('creates playable portrait crop and landscape blur clips with real audio and exact cuts', function () {
    if (! (new ExecutableFinder)->find((string) config('social_video.ffmpeg'))
        || ! (new ExecutableFinder)->find((string) config('social_video.ffprobe'))) {
        $this->markTestSkipped('FFmpeg and FFprobe are required for this media integration test.');
    }
    Storage::fake('local');
    $disk = Storage::disk('local');
    $project = SocialVideoProject::factory()->create(['status' => 'pending']);
    $disk->makeDirectory(dirname($project->source_path));
    Process::timeout(20)->run([
        (string) config('social_video.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', 'testsrc2=size=320x180:rate=10',
        '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=44100',
        '-t', '2.4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $disk->path($project->source_path),
    ])->throw();
    $processor = app(SocialVideoProcessor::class);
    (new PrepareSocialVideoJob($project->id))->handle($processor);
    expect($project->refresh()->status)->toBe('ready');
    expect($project->duration_ms)->toBe(2400);
    $disk->assertExists($project->preview_path);

    foreach ([['portrait', 'crop', 720, 1280], ['landscape', 'blur', 1280, 720]] as $index => [$format, $framing, $width, $height]) {
        $clip = SocialVideoClip::factory()->for($project, 'project')->create([
            'position' => $index + 1, 'start_ms' => 500, 'end_ms' => 1700,
            'format' => $format, 'framing' => $framing, 'focal_x' => 0, 'focal_y' => 100,
        ]);
        (new RenderSocialVideoClipJob($clip->id))->handle($processor);
        expect($clip->refresh()->status)->toBe('ready');
        $metadata = $processor->inspect($disk->path($clip->path));
        expect($metadata['width'])->toBe($width);
        expect($metadata['height'])->toBe($height);
        expect(abs($metadata['duration_ms'] - 1200))->toBeLessThanOrEqual(120);
        $audio = Process::timeout(10)->run([
            (string) config('social_video.ffprobe'), '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_name', '-of', 'json', $disk->path($clip->path),
        ])->throw();
        expect(json_decode($audio->output(), true)['streams'][0]['codec_name'])->toBe('aac');
    }
    $disk->assertExists($project->source_path);
});

it('records terminal job timeouts so preparation does not remain busy forever', function () {
    $project = SocialVideoProject::factory()->create(['status' => 'processing']);
    $clip = SocialVideoClip::factory()->for($project, 'project')->create(['status' => 'processing']);
    (new PrepareSocialVideoJob($project->id))->failed(new RuntimeException('timeout'));
    (new RenderSocialVideoClipJob($clip->id))->failed(new RuntimeException('timeout'));
    expect($project->refresh()->status)->toBe('failed');
    expect($clip->refresh()->status)->toBe('failed');
});

it('keeps a maximum-length wide caption inside the image without clipping its text', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $disk->makeDirectory('captions');
    app(\App\Services\Social\SocialVideoCaptionRenderer::class)->timeline([
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => str_repeat('W', 160)],
    ], $disk->path('captions'), 720, 1000, 'yellow');
    $image = imagecreatefrompng($disk->path('captions/caption-0.png'));
    expect(imagecolorat($image, 360, 0) >> 24 & 127)->toBe(127);
    expect(imagecolorat($image, 360, imagesy($image) - 1) >> 24 & 127)->toBe(127);
    $yellow = 0;
    for ($y = 0; $y < imagesy($image); $y += 2) {
        for ($x = 0; $x < imagesx($image); $x += 2) {
            $rgb = imagecolorat($image, $x, $y);
            if (($rgb >> 16 & 255) > 180 && ($rgb >> 8 & 255) > 150 && ($rgb & 255) < 130) {
                $yellow++;
            }
        }
    }
    imagedestroy($image);
    expect($yellow)->toBeGreaterThan(100);
});

it('burns UTF-8 captions at clip-relative times and animates the crop in a real MP4', function () {
    if (! (new ExecutableFinder)->find((string) config('social_video.ffmpeg'))) {
        $this->markTestSkipped('FFmpeg is required for this media integration test.');
    }
    Storage::fake('local');
    $disk = Storage::disk('local');
    $project = SocialVideoProject::factory()->create(['duration_ms' => 3000, 'settings' => [
        'captions_enabled' => true, 'caption_style' => 'yellow', 'caption_position' => 'bottom',
        'captions' => [['start_ms' => 0, 'end_ms' => 800, 'text' => 'Été à Montréal'], ['start_ms' => 1500, 'end_ms' => 2800, 'text' => 'Texte {non interprété}']],
        'crop_points' => [['time_ms' => 500, 'x' => 0, 'y' => 50], ['time_ms' => 2500, 'x' => 100, 'y' => 50]],
    ]]);
    $disk->makeDirectory(dirname($project->source_path));
    Process::timeout(20)->run([
        (string) config('social_video.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
        '-f', 'lavfi', '-i', 'color=red:size=320x180:rate=10', '-vf', 'drawbox=x=160:y=0:w=160:h=180:color=blue:t=fill',
        '-t', '3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $disk->path($project->source_path),
    ])->throw();
    $clip = SocialVideoClip::factory()->for($project, 'project')->create(['start_ms' => 500, 'end_ms' => 2500, 'format' => 'portrait', 'framing' => 'crop']);
    (new RenderSocialVideoClipJob($clip->id))->handle(app(SocialVideoProcessor::class));
    expect($clip->refresh()->status)->toBe('ready');
    expect(app(SocialVideoProcessor::class)->inspect($disk->path($clip->path))['duration_ms'])->toBe(2000);

    foreach ([[0.1, true, 'red'], [0.5, false, null], [1.8, true, 'blue']] as $index => [$time, $visible, $color]) {
        $frame = $disk->path('frame-'.$index.'.png');
        Process::timeout(10)->run([(string) config('social_video.ffmpeg'), '-loglevel', 'error', '-y',
            '-ss', (string) $time, '-i', $disk->path($clip->path), '-frames:v', '1', $frame])->throw();
        $image = imagecreatefrompng($frame);
        $yellow = 0;
        for ($y = 920; $y < 1160; $y += 2) {
            for ($x = 50; $x < 670; $x += 2) {
                $rgb = imagecolorat($image, $x, $y);
                if (($rgb >> 16 & 255) > 180 && ($rgb >> 8 & 255) > 150 && ($rgb & 255) < 130) {
                    $yellow++;
                }
            }
        }
        expect($yellow > 5)->toBe($visible);
        $center = imagecolorat($image, 360, 100);
        if ($color === 'red') {
            expect($center >> 16 & 255)->toBeGreaterThan(200);
            expect($center & 255)->toBeLessThan(20);
        } elseif ($color === 'blue') {
            expect($center & 255)->toBeGreaterThan(200);
            expect($center >> 16 & 255)->toBeLessThan(20);
        }
        imagedestroy($image);
    }
    expect($disk->directories(dirname($project->source_path)))->toBe([]);
});
