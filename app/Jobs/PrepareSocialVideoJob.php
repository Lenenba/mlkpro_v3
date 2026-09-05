<?php

namespace App\Jobs;

use App\Models\SocialVideoProject;
use App\Services\Social\SocialVideoProcessor;
use App\Support\QueueWorkload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrepareSocialVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public int $projectId)
    {
        $this->onQueue(QueueWorkload::queue('social_video'));
    }

    /**
     * Execute the job.
     */
    public function handle(SocialVideoProcessor $processor): void
    {
        $claimed = SocialVideoProject::query()->whereKey($this->projectId)
            ->where('status', 'pending')->update(['status' => 'processing']);
        if (! $claimed) {
            return;
        }

        $project = SocialVideoProject::query()->findOrFail($this->projectId);
        $disk = Storage::disk('local');
        $preview = dirname($project->source_path).'/preview.mp4';
        try {
            $metadata = $processor->inspect($disk->path($project->source_path));
            $processor->preview($disk->path($project->source_path), $disk->path($preview));
            $project->update([...$metadata, 'status' => 'ready', 'preview_path' => $preview, 'error_code' => null]);
        } catch (Throwable $exception) {
            $disk->delete($preview);
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $code = in_array($exception?->getMessage(), ['invalid_video', 'video_too_long'], true)
            ? $exception->getMessage() : 'processing_failed';
        SocialVideoProject::query()->whereKey($this->projectId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error_code' => $code]);
    }
}
