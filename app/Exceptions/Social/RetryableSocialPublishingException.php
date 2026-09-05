<?php

namespace App\Exceptions\Social;

use RuntimeException;
use Throwable;

class RetryableSocialPublishingException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly bool $remoteEffectImpossible = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function provenSafeForCreateRetry(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ): self {
        return new self($message, $code, $previous, true);
    }

    public function remoteEffectIsImpossible(): bool
    {
        return $this->remoteEffectImpossible;
    }
}
