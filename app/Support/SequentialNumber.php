<?php

namespace App\Support;

class SequentialNumber
{
    public static function generateNext(?string $lastNumber, string $defaultPrefix = 'X', int $padding = 3): string
    {
        if ($lastNumber === null) {
            return $defaultPrefix.str_pad('1', $padding, '0', STR_PAD_LEFT);
        }

        preg_match('/([A-Z]+)(\d+)/', $lastNumber, $matches);

        if (! isset($matches[2])) {
            throw new \InvalidArgumentException("Invalid number format: {$lastNumber}");
        }

        $prefix = $matches[1] ?? $defaultPrefix;
        $nextNumericPart = ((int) $matches[2]) + 1;

        return $prefix.str_pad((string) $nextNumericPart, $padding, '0', STR_PAD_LEFT);
    }
}
