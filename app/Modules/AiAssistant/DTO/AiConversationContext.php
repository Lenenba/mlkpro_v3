<?php

namespace App\Modules\AiAssistant\DTO;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Support\Collection;

class AiConversationContext
{
    /**
     * @param  Collection<int, mixed>  $services
     * @param  Collection<int, mixed>  $messages
     * @param  Collection<int, mixed>  $knowledgeItems
     * @param  array<string, mixed>  $bookingRules
     */
    public function __construct(
        public readonly User $tenant,
        public readonly AiAssistantSetting $settings,
        public readonly AiConversation $conversation,
        public readonly Collection $services,
        public readonly Collection $messages,
        public readonly Collection $knowledgeItems,
        public readonly array $bookingRules
    ) {}
}
