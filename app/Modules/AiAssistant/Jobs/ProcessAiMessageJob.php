<?php

namespace App\Modules\AiAssistant\Jobs;

use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Services\AiAssistantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAiMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId
    ) {}

    public function handle(AiAssistantService $assistant): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        $message = AiMessage::query()->find($this->messageId);
        if (! $conversation || ! $message) {
            return;
        }

        $response = $assistant->handleUserMessage($conversation, (string) $message->content);
        $assistant->recordAssistantMessage($conversation, $response);
    }
}
