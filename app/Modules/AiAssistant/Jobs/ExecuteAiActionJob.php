<?php

namespace App\Modules\AiAssistant\Jobs;

use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Services\AiActionExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAiActionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $actionId
    ) {}

    public function handle(AiActionExecutor $executor): void
    {
        $action = AiAction::query()->find($this->actionId);
        if (! $action) {
            return;
        }

        $executor->execute($action);
    }
}
