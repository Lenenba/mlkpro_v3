<?php

namespace App\Modules\AiAssistant\DTO;

class AiDetectedIntent
{
    public function __construct(
        public readonly string $intent,
        public readonly float $confidence,
        public readonly string $language,
        public readonly array $entities = []
    ) {}
}
