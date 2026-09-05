<?php

namespace App\Jobs;

use App\Models\SocialVideoProject;
use App\Services\Social\SocialVideoIntelligenceService;
use App\Support\QueueWorkload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessSocialVideoIntelligenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $options */
    public function __construct(public int $projectId, public string $task, public string $runId, public array $options = [])
    {
        $this->onQueue(QueueWorkload::queue('social_video'));
    }

    public function handle(SocialVideoIntelligenceService $intelligence): void
    {
        if (! SocialVideoProject::query()->whereKey($this->projectId)->where('intelligence_run_id', $this->runId)->where('intelligence_status', 'pending')
            ->update(['intelligence_status' => 'processing'])) {
            return;
        }
        $project = SocialVideoProject::query()->with(['user', 'clips'])->findOrFail($this->projectId);
        try {
            $data = match ($this->task) {
                'transcribe' => ['captions' => $intelligence->transcribe($project)],
                'suggest' => ['suggestions' => $intelligence->suggest($project, (int) $this->options['seconds'])],
                'framing' => ['crop_points' => $intelligence->framing($project, $this->options['format'], $this->options['subject']), 'crop_format' => $this->options['format']],
                'texts' => ['texts' => $intelligence->texts($project, $this->options['connection_ids'])],
            };
            DB::transaction(function () use ($data): void {
                $locked = SocialVideoProject::query()->lockForUpdate()->find($this->projectId);
                if (! $locked || $locked->intelligence_run_id !== $this->runId || $locked->intelligence_status !== 'processing') {
                    return;
                }
                $previous = $locked->intelligence ?? [];
                if ($this->task === 'transcribe') {
                    unset($previous['suggestions'], $previous['texts']);
                }
                $locked->update(['intelligence' => [...$previous, ...$data],
                    'intelligence_status' => 'ready', 'intelligence_error_code' => null]);
            });
        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $code = in_array($exception?->getMessage(), ['no_audio', 'no_speech', 'invalid_ai_response', 'uncertain_subject'], true)
            ? $exception->getMessage() : 'intelligence_failed';
        SocialVideoProject::query()->whereKey($this->projectId)->where('intelligence_run_id', $this->runId)->whereIn('intelligence_status', ['pending', 'processing'])
            ->update(['intelligence_status' => 'failed', 'intelligence_error_code' => $code]);
    }
}
