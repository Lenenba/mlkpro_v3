<?php

namespace App\Services\CRM\Connectors;

use App\Services\CRM\Connectors\Contracts\CrmConnectorAdapter;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

abstract class AbstractCrmConnectorAdapter implements CrmConnectorAdapter
{
    protected function unsupported(string $family, string $event): never
    {
        throw new InvalidArgumentException(sprintf(
            'Connector [%s] does not support %s event [%s].',
            $this->key(),
            $family,
            $event
        ));
    }

    protected function resolveString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    protected function resolveNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function resolveBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }

        return null;
    }

    protected function filtered(array $properties): array
    {
        return array_filter($properties, function ($value): bool {
            if ($value === null) {
                return false;
            }

            if (is_string($value)) {
                return trim($value) !== '';
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveMessageEmail(string $direction, array $payload): ?string
    {
        $keys = $direction === 'inbound'
            ? ['from_email', 'sender_email', 'email']
            : ['to_email', 'recipient_email', 'email'];

        foreach ($keys as $key) {
            $value = $this->resolveString($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveFirstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->resolveString($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
