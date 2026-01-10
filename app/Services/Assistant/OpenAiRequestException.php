<?php

namespace App\Services\Assistant;

use RuntimeException;

class OpenAiRequestException extends RuntimeException
{
    private int $status;
    private ?string $type;
    private ?string $apiMessage;

    public function __construct(int $status, ?string $type = null, ?string $apiMessage = null)
    {
        $message = $apiMessage ?: 'OpenAI request failed.';
        parent::__construct($message, $status);
        $this->status = $status;
        $this->type = $type;
        $this->apiMessage = $apiMessage;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function apiMessage(): ?string
    {
        return $this->apiMessage;
    }

    public function userMessage(): string
    {
        if ($this->type === 'insufficient_quota') {
            return 'Quota OpenAI depasse. Merci de verifier votre abonnement.';
        }

        if ($this->type === 'invalid_api_key' || $this->status === 401) {
            return 'Cle OpenAI invalide. Merci de verifier la configuration.';
        }

        if ($this->type === 'rate_limit_exceeded' || $this->status === 429) {
            return 'Limite atteinte. Reessayez dans quelques minutes.';
        }

        if ($this->status >= 500) {
            return 'Service IA indisponible. Reessayez plus tard.';
        }

        return 'Assistant indisponible pour le moment.';
    }
}
