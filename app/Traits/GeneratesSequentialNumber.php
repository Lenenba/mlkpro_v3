<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait GeneratesSequentialNumber
{
    /**
     * Generate a sequential number for a model, scoped by a user.
     *
     * @param  int    $userId    The ID of the user for whom the number is generated.
     * @param  string $prefix    The prefix to use for the number (e.g., 'Cust', 'Quote', etc.).
     * @param  int    $padding   The number of digits to pad (default: 3).
     * @return string            The generated number with the prefix.
     */
    public static function generateNumber(int $userId, string $prefix, int $padding = 3): string
    {
        return DB::transaction(function () use ($userId, $prefix, $padding) {
            // Count the number of records for the user
            $count = self::where('user_id', $userId)->lockForUpdate()->count();

            // Generate the sequential number
            $nextNumber = str_pad($count + 1, $padding, '0', STR_PAD_LEFT);

            return "{$prefix}{$nextNumber}";
        });
    }
}
