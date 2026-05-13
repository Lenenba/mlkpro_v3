<?php

namespace App\Modules\AiAssistant\Jobs;

use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Services\AiConversationSummarizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SummarizeAiConversationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $conversationId
    ) {}

    public function handle(AiConversationSummarizer $summarizer): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        if (! $conversation) {
            return;
        }

        $summarizer->summarize($conversation);
    }
}
