<?php

namespace App\Services\Segments\Contracts;

use App\Models\SavedSegment;

interface SegmentModuleResolver
{
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function resolve(SavedSegment $segment): array;
}
