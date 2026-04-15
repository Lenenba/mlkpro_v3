<?php

namespace App\Support\BulkActions;

interface BulkActionModule
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function definition(array $context = []): array;
}
