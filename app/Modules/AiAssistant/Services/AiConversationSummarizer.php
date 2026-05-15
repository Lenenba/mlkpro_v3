<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Models\AiConversation;

class AiConversationSummarizer
{
    public function summarize(AiConversation $conversation): string
    {
        $messages = $conversation->messages()
            ->latest()
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn ($message): string => ucfirst((string) $message->sender_type).': '.trim((string) $message->content))
            ->implode("\n");

        $summary = trim($messages) !== ''
            ? $messages
            : 'No conversation content yet.';

        $conversation->update([
            'summary' => $summary,
        ]);

        return $summary;
    }
}
