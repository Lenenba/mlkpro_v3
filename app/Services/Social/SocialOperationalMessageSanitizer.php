<?php

namespace App\Services\Social;

use Illuminate\Support\Str;

final class SocialOperationalMessageSanitizer
{
    private const MAX_MESSAGE_LENGTH = 2000;

    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEY_PATTERN = '(?:authorization|proxy[_-]?authorization|cookie|set[_-]?cookie|password|passwd|api[_-]?key|x[_-]?api[_-]?key|x[_-]?auth[_-]?token|x[_-]?signature|x[_-]?hub[_-]?signature(?:[_-]?256)?|signature|sig|oauth[_-]?code|code[_-]?verifier|private[_-]?key|[a-z0-9_-]*(?:token|secret)[a-z0-9_-]*|x[_-]?amz[_-]?[a-z0-9_-]+)';

    public function sanitize(?string $message, ?string $fallback = null): ?string
    {
        $message = trim(strip_tags((string) $message));

        if ($message === '') {
            $message = trim(strip_tags((string) $fallback));
        }

        if ($message === '') {
            return null;
        }

        $message = $this->redactPrivateKeys($message);
        $message = $this->redactSensitiveHeaders($message);
        $message = $this->redactAuthorizationSchemes($message);
        $message = $this->redactStructuredSensitiveValues($message);
        $message = $this->replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
        $message = Str::squish($message);

        return $message === ''
            ? null
            : Str::limit($message, self::MAX_MESSAGE_LENGTH, '');
    }

    private function redactPrivateKeys(string $message): string
    {
        return $this->replace(
            '~-----BEGIN(?: [A-Z0-9]+)* PRIVATE KEY-----[\s\S]*?(?:-----END(?: [A-Z0-9]+)* PRIVATE KEY-----|$)~iu',
            'Private key '.self::REDACTED,
            $message,
        );
    }

    private function redactSensitiveHeaders(string $message): string
    {
        return $this->replace(
            '~\b(authorization|proxy-authorization|cookie|set-cookie|x-api-key|x-auth-token|x-signature|x-hub-signature(?:-256)?)\s*:\s*[^\r\n]*~iu',
            '$1: '.self::REDACTED,
            $message,
        );
    }

    private function redactAuthorizationSchemes(string $message): string
    {
        return $this->replace(
            '~\b(Basic|Bearer)\s+[^\s,;]+~iu',
            '$1 '.self::REDACTED,
            $message,
        );
    }

    private function redactStructuredSensitiveValues(string $message): string
    {
        $message = $this->replace(
            '~((?:["\x27])'.self::SENSITIVE_KEY_PATTERN.'(?:["\x27])\s*:\s*)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|[^,\s}\]]+)~iu',
            '$1"'.self::REDACTED.'"',
            $message,
        );

        return $this->replace(
            '~(\b'.self::SENSITIVE_KEY_PATTERN.'\b\s*[:=]\s*)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|[^\s&,#}\]]+)~iu',
            '$1'.self::REDACTED,
            $message,
        );
    }

    private function replace(string $pattern, string $replacement, string $message): string
    {
        return preg_replace($pattern, $replacement, $message) ?? '';
    }
}
