<?php

namespace App\Jobs;

use App\Models\SocialVideoClip;
use App\Services\Social\SocialVideoProcessor;
use App\Support\QueueWorkload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderSocialVideoClipJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public int $clipId)
    {
        $this->onQueue(QueueWorkload::queue('social_video'));
    }

    /**
     * Execute the job.
     */
    public function handle(SocialVideoProcessor $processor): void
    {
        $claimed = SocialVideoClip::query()->whereKey($this->clipId)
            ->where('status', 'pending')->update(['status' => 'processing']);
        if (! $claimed) {
            return;
        }
        $clip = SocialVideoClip::query()->with('project')->findOrFail($this->clipId);
        $disk = Storage::disk('local');
        $path = dirname($clip->project->source_path).'/clip-'.$clip->id.'.mp4';
        try {
            $processor->render($disk->path($clip->project->source_path), $disk->path($path), $clip);
            $clip->update(['status' => 'ready', 'path' => $path, 'error_code' => null]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        SocialVideoClip::query()->whereKey($this->clipId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error_code' => 'processing_failed']);
    }
}
