<?php

namespace App\Modules\AiAssistant\DTO;

use App\Modules\AiAssistant\Models\AiAction;

class AiAssistantResponse
{
    /**
     * @param  array<int, AiAction>  $actions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $message,
        public readonly string $status = 'open',
        public readonly array $actions = [],
        public readonly array $metadata = []
    ) {}
}
