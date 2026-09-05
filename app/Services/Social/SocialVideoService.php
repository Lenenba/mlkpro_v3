<?php

namespace App\Services\Social;

use App\Jobs\PrepareSocialVideoJob;
use App\Jobs\RenderSocialVideoClipJob;
use App\Models\SocialVideoProject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SocialVideoService
{
    public function beginUpload(User $owner, User $actor, string $name, int $size): SocialVideoProject
    {
        $path = 'social/videos/'.$owner->id.'/'.Str::uuid().'/source.mp4';
        if (! Storage::disk('local')->put($path, '')) {
            throw new RuntimeException('Video storage failed.');
        }
        try {
            return SocialVideoProject::query()->create([
                'user_id' => $owner->id, 'created_by_user_id' => $actor->id,
                'name' => Str::limit(pathinfo($name, PATHINFO_FILENAME), 200, ''),
                'source_path' => $path, 'size' => $size, 'status' => 'uploading',
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory(dirname($path));
            throw $exception;
        }
    }

    public function appendUpload(SocialVideoProject $project, UploadedFile $chunk, int $offset): SocialVideoProject
    {
        $complete = DB::transaction(function () use ($project, $chunk, $offset): bool {
            $locked = SocialVideoProject::query()->lockForUpdate()->findOrFail($project->id);
            $path = Storage::disk('local')->path($locked->source_path);
            $contents = $chunk->getContent();
            $length = strlen($contents);
            if ($length === 0 || $offset < 0 || $offset + $length > $locked->size) {
                throw ValidationException::withMessages(['chunk' => __('social_video.invalid_upload')]);
            }
            $stream = fopen($path, 'c+b');
            if ($stream === false) {
                throw new RuntimeException('Video storage failed.');
            }
            try {
                $received = (int) fstat($stream)['size'];
                if ($offset < $received && $offset + $length <= $received) {
                    fseek($stream, $offset);
                    if (! hash_equals($contents, (string) fread($stream, $length))) {
                        throw ValidationException::withMessages(['chunk' => __('social_video.invalid_upload')]);
                    }
                    if ($locked->status === 'uploading' && $received === $locked->size) {
                        $locked->update(['status' => 'pending']);

                        return true;
                    }

                    return false;
                }
                if ($locked->status !== 'uploading' || $offset !== $received) {
                    throw ValidationException::withMessages(['chunk' => __('social_video.invalid_upload')]);
                }
                fseek($stream, $received);
                if (fwrite($stream, $contents) !== $length) {
                    ftruncate($stream, $received);
                    throw new RuntimeException('Video storage failed.');
                }
                if ($received + $length === $locked->size) {
                    $locked->update(['status' => 'pending']);

                    return true;
                }

                return false;
            } finally {
                fclose($stream);
            }
        });
        if ($complete) {
            $this->queuePreparation($project);
        }

        return $project->refresh();
    }

    public function store(User $owner, User $actor, UploadedFile $file): SocialVideoProject
    {
        $directory = 'social/videos/'.$owner->id.'/'.Str::uuid();
        $path = $file->storeAs($directory, 'source.'.$file->guessExtension(), 'local');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Video storage failed.');
        }

        try {
            $project = DB::transaction(fn () => SocialVideoProject::query()->create([
                'user_id' => $owner->id, 'created_by_user_id' => $actor->id,
                'name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 200, ''),
                'source_path' => $path, 'size' => $file->getSize(), 'status' => 'pending',
            ]));
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory($directory);
            throw $exception;
        }

        $this->queuePreparation($project);

        return $project->refresh();
    }

    private function queuePreparation(SocialVideoProject $project): void
    {
        try {
            PrepareSocialVideoJob::dispatch($project->id)->afterCommit();
        } catch (Throwable $exception) {
            $project->update(['status' => 'failed', 'error_code' => 'processing_failed']);
            report($exception);
        }
    }

    /**
     * @param  array{mode: string, segment_seconds?: int, segments?: list<array{start_ms: int, end_ms: int}>, format: string, framing: string, focal_x: int, focal_y: int}  $settings
     * @return list<array{start_ms: int, end_ms: int}>
     */
    public function segments(int $duration, array $settings): array
    {
        $segments = $settings['segments'] ?? [];
        if ($settings['mode'] === 'duration') {
            $step = (int) ($settings['segment_seconds'] ?? 0) * 1000;
            if ($step < 1000) {
                throw ValidationException::withMessages(['segment_seconds' => __('social_video.invalid_segments')]);
            }
            $segments = [];
            if ((int) ceil($duration / $step) > (int) config('social_video.max_clips')) {
                throw ValidationException::withMessages(['segment_seconds' => __('social_video.too_many_clips')]);
            }
            for ($start = 0; $start < $duration; $start += $step) {
                $segments[] = ['start_ms' => $start, 'end_ms' => min($start + $step, $duration)];
            }
        }

        if ($segments === [] || count($segments) > (int) config('social_video.max_clips')) {
            throw ValidationException::withMessages(['segments' => __('social_video.too_many_clips')]);
        }
        $previousEnd = 0;
        foreach ($segments as $segment) {
            $start = (int) $segment['start_ms'];
            $end = (int) $segment['end_ms'];
            if ($start < $previousEnd || $end <= $start || $end > $duration
                || $end - $start > (int) config('social_video.max_clip_duration_ms')) {
                throw ValidationException::withMessages(['segments' => __('social_video.invalid_segments')]);
            }
            $previousEnd = $end;
        }

        return array_values($segments);
    }

    /** @param array<string, mixed> $settings */
    public function render(SocialVideoProject $project, array $settings): SocialVideoProject
    {
        $oldPaths = DB::transaction(function () use ($project, $settings): array {
            $locked = SocialVideoProject::query()->lockForUpdate()->findOrFail($project->id);
            if ($locked->status !== 'ready' || in_array($locked->intelligence_status, ['pending', 'processing'], true)
                || $locked->clips()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw ValidationException::withMessages(['video' => __('social_video.busy')]);
            }
            if ($locked->clips()->whereNotNull('publication_ids')->exists()) {
                throw ValidationException::withMessages(['video' => __('social_video.already_planned')]);
            }
            $segments = $this->segments($locked->duration_ms, $settings);
            app(SocialVideoEditingService::class)->validate($settings, $locked->duration_ms);
            $oldPaths = $locked->clips()->pluck('path')->filter()->all();
            $locked->clips()->delete();
            $intelligence = $locked->intelligence ?? [];
            unset($intelligence['texts']);
            $locked->update(['settings' => [...$settings, 'segments' => $segments], 'intelligence' => $intelligence]);
            foreach ($segments as $position => $segment) {
                $locked->clips()->create([
                    ...$segment, 'position' => $position + 1,
                    'format' => $settings['format'], 'framing' => $settings['framing'],
                    'focal_x' => $settings['focal_x'], 'focal_y' => $settings['focal_y'],
                ]);
            }

            return $oldPaths;
        });
        Storage::disk('local')->delete($oldPaths);

        foreach ($project->clips()->get() as $clip) {
            try {
                RenderSocialVideoClipJob::dispatch($clip->id)->afterCommit();
            } catch (Throwable $exception) {
                $clip->update(['status' => 'failed', 'error_code' => 'processing_failed']);
                report($exception);
            }
        }

        return $project->refresh();
    }

    /** @return array<string, mixed> */
    public function payload(SocialVideoProject $project): array
    {
        $project->loadMissing('clips');

        return [
            'id' => $project->id, 'name' => $project->name, 'size' => $project->size,
            'duration_ms' => $project->duration_ms, 'width' => $project->width, 'height' => $project->height,
            'status' => $project->status, 'error_code' => $project->error_code,
            'settings' => $project->settings,
            'intelligence' => $project->intelligence,
            'intelligence_status' => $project->intelligence_status,
            'intelligence_error_code' => $project->intelligence_error_code,
            'preview_url' => $project->status === 'ready' ? route('social.videos.preview', $project) : null,
            'clips' => $project->clips->map(fn ($clip): array => [
                'id' => $clip->id, 'position' => $clip->position, 'start_ms' => $clip->start_ms,
                'end_ms' => $clip->end_ms, 'format' => $clip->format, 'framing' => $clip->framing,
                'status' => $clip->status, 'error_code' => $clip->error_code,
                'publication_ids' => $clip->publication_ids,
                'preview_url' => $clip->status === 'ready'
                    ? route('social.videos.clips.preview', [$project, $clip]) : null,
            ])->all(),
        ];
    }
}
