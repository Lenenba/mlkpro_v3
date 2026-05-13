<?php

namespace App\Modules\AiAssistant\Actions;

use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;

class RequestHumanReviewAction
{
    public function execute(AiAction $action): AiConversation
    {
        $conversation = $action->conversation()->firstOrFail();
        $conversation->update([
            'status' => AiConversation::STATUS_WAITING_HUMAN,
        ]);

        return $conversation;
    }
}
