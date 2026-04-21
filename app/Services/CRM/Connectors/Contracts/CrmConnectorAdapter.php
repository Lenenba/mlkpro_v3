<?php

namespace App\Services\CRM\Connectors\Contracts;

interface CrmConnectorAdapter
{
    public function key(): string;

    public function label(): string;

    public function supportsMessageEvents(): bool;

    public function supportsMeetingEvents(): bool;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, description: string, properties: array<string, mixed>}
     */
    public function normalizeMessageEvent(string $event, array $payload = []): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, description: string, properties: array<string, mixed>}
     */
    public function normalizeMeetingEvent(string $event, array $payload = []): array;
}
