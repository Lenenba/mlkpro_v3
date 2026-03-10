<?php

namespace App\Jobs;

use App\Models\Work;
use App\Services\WorkScheduleService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateWorkTasks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $workId;

    public ?int $createdByUserId;

    public array $dateStrings;

    public function __construct(int $workId, ?int $createdByUserId = null, array $dateStrings = [])
    {
        $this->workId = $workId;
        $this->createdByUserId = $createdByUserId;
        $this->dateStrings = $dateStrings;
        $this->onQueue(QueueWorkload::queue('works'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('works', [60, 300, 900]);
    }

    public function handle(WorkScheduleService $scheduleService): void
    {
        $work = Work::query()->find($this->workId);
        if (! $work) {
            return;
        }

        if ($this->dateStrings) {
            $scheduleService->generateTasksForDates($work, $this->dateStrings, $this->createdByUserId);

            return;
        }

        $scheduleService->generateTasks($work, $this->createdByUserId);
    }
}
