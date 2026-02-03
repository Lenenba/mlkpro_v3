<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Support\Facades\Hash;

class TwoFactorService
{
    public const CODE_LENGTH = 6;
    public const EXPIRY_MINUTES = 10;
    public const RESEND_COOLDOWN_SECONDS = 30;

    public function sendCode(User $user, bool $force = false): array
    {
        $now = now();
        $lastSent = $user->two_factor_last_sent_at;
        $cooldown = self::RESEND_COOLDOWN_SECONDS;

        if (!$force && $lastSent && $lastSent->diffInSeconds($now) < $cooldown) {
            return [
                'sent' => false,
                'retry_after' => $cooldown - $lastSent->diffInSeconds($now),
                'expires_at' => $user->two_factor_expires_at,
            ];
        }

        $code = $this->generateCode();
        $expiresAt = $now->copy()->addMinutes(self::EXPIRY_MINUTES);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_code' => Hash::make($code),
            'two_factor_expires_at' => $expiresAt,
            'two_factor_last_sent_at' => $now,
        ])->save();

        $user->notify(new TwoFactorCodeNotification($code, $expiresAt));

        return [
            'sent' => true,
            'retry_after' => 0,
            'expires_at' => $expiresAt,
        ];
    }

    public function verifyCode(User $user, string $code): bool
    {
        $expiresAt = $user->two_factor_expires_at;
        if (!$user->two_factor_code || !$expiresAt || now()->greaterThan($expiresAt)) {
            return false;
        }

        if (!Hash::check($code, $user->two_factor_code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ])->save();

        return true;
    }

    private function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;
        $code = (string) random_int(0, $max);

        return str_pad($code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
