<?php

namespace App\Traits;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Generate a sequential number scoped by user and customer.
     *
     * @param  int    $customerId The ID of the customer.
     * @param  string $prefix     The prefix to use for the number.
     * @param  int    $padding    The number of digits to pad (default: 3).
     * @return string             The generated number with the prefix.
     */
    public static function generateScopedNumber(int $customerId, string $prefix, int $padding = 3): string
    {
        return DB::transaction(function () use ($customerId, $prefix, $padding) {
            // Ensure the `user_id` is scoped by the authenticated user (or you can pass it explicitly)
            $id = Customer::where('id', $customerId)->value('user_id');
            $userId = Auth::user()->id ?? $id; // Get the authenticated user's ID

            // Count existing quotes scoped by user and customer
            $count = self::where('user_id', $userId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->count();

            // Generate the next number
            $nextNumber = str_pad($count + 1, $padding, '0', STR_PAD_LEFT);

            return "{$prefix}{$nextNumber}";
        });
    }

    /**
     * Generate the next number in a sequence.
     *
     * @param  string|null $lastNumber The last number in the sequence.
     * @return string                  The next number in the sequence.
     */
    public static function generateNextNumber($lastNumber): string
    {
        // Si aucun numéro précédent, retourner le premier
        if (is_null($lastNumber)) {
            return 'Q001';
        }

        // Extraire la partie numérique du dernier numéro
        preg_match('/Q(\d+)/', $lastNumber, $matches);

        if (!isset($matches[1])) {
            throw new \Exception("Invalid number format: $lastNumber");
        }

        $lastNumericPart = (int) $matches[1];

        // Incrémenter la partie numérique
        $nextNumericPart = $lastNumericPart + 1;

        // Générer le nouveau numéro en format "Q" suivi de 3 chiffres
        return 'Q' . str_pad($nextNumericPart, 3, '0', STR_PAD_LEFT);
    }
}
