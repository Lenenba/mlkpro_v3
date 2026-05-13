<?php

namespace App\Modules\AiAssistant\DTO;

class AiProposedAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload
    ) {}
}
