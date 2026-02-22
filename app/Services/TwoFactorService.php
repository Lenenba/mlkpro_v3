<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class TwoFactorService
{
    public const METHOD_EMAIL = 'email';
    public const METHOD_SMS = 'sms';
    public const METHOD_APP = 'app';

    public const CODE_LENGTH = 6;
    public const EXPIRY_MINUTES = 10;
    public const RESEND_COOLDOWN_SECONDS = 30;

    public function sendCode(User $user, bool $force = false, ?string $preferredMethod = null): array
    {
        $now = now();
        $lastSent = $user->two_factor_last_sent_at;
        $cooldown = self::RESEND_COOLDOWN_SECONDS;
        $resolvedMethod = $this->resolveEffectiveMethod($user, $preferredMethod);

        if (!$force && $lastSent && $lastSent->diffInSeconds($now) < $cooldown) {
            return [
                'sent' => false,
                'retry_after' => $cooldown - $lastSent->diffInSeconds($now),
                'expires_at' => $user->two_factor_expires_at,
                'method' => $resolvedMethod,
                'reason' => 'cooldown',
            ];
        }

        if ($resolvedMethod === self::METHOD_APP) {
            return [
                'sent' => false,
                'retry_after' => 0,
                'expires_at' => null,
                'method' => self::METHOD_APP,
                'reason' => 'app_method',
            ];
        }

        $code = $this->generateCode();
        $expiresAt = $now->copy()->addMinutes(self::EXPIRY_MINUTES);

        $delivery = $this->deliverCode($user, $resolvedMethod, $code, $expiresAt);
        if (!$delivery['sent'] && $resolvedMethod === self::METHOD_SMS) {
            $delivery = $this->deliverCode($user, self::METHOD_EMAIL, $code, $expiresAt);
        }

        if (!$delivery['sent']) {
            return [
                'sent' => false,
                'retry_after' => 0,
                'expires_at' => null,
                'method' => $delivery['method'] ?? $resolvedMethod,
                'reason' => $delivery['reason'] ?? 'delivery_failed',
            ];
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_code' => Hash::make($code),
            'two_factor_expires_at' => $expiresAt,
            'two_factor_last_sent_at' => $now,
        ])->save();

        return [
            'sent' => true,
            'retry_after' => 0,
            'expires_at' => $expiresAt,
            'method' => $delivery['method'] ?? $resolvedMethod,
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

    public function resolveEffectiveMethod(User $user, ?string $preferredMethod = null): string
    {
        $method = $preferredMethod ?: $user->twoFactorMethod();
        if ($method === self::METHOD_APP) {
            return !empty($user->two_factor_secret) ? self::METHOD_APP : self::METHOD_EMAIL;
        }

        if ($method === self::METHOD_SMS) {
            return $this->canUseSms($user) ? self::METHOD_SMS : self::METHOD_EMAIL;
        }

        return self::METHOD_EMAIL;
    }

    public function smsCapability(User $user): array
    {
        $phone = trim((string) $user->phone_number);
        $hasPhone = $phone !== '';
        $twilioConfigured = $this->isTwilioConfigured();
        $companyEnabled = $this->isSmsEnabledForCompany($user);

        return [
            'available' => $hasPhone && $twilioConfigured && $companyEnabled,
            'has_phone' => $hasPhone,
            'phone_hint' => $hasPhone ? $this->maskedPhoneNumber($phone) : null,
            'twilio_configured' => $twilioConfigured,
            'company_enabled' => $companyEnabled,
        ];
    }

    public function maskedPhoneNumber(?string $value): ?string
    {
        $phone = trim((string) $value);
        if ($phone === '') {
            return null;
        }

        $prefix = str_starts_with($phone, '+') ? '+' : '';
        $digits = ltrim($phone, '+');
        $length = strlen($digits);
        if ($length <= 4) {
            return $prefix . $digits;
        }

        return $prefix . str_repeat('*', $length - 4) . substr($digits, -4);
    }

    private function canUseSms(User $user): bool
    {
        return (bool) ($this->smsCapability($user)['available'] ?? false);
    }

    private function isTwilioConfigured(): bool
    {
        return (bool) config('services.twilio.sid')
            && (bool) config('services.twilio.token')
            && (bool) config('services.twilio.from');
    }

    private function isSmsEnabledForCompany(User $user): bool
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (!$owner) {
            return false;
        }

        return app(CompanyNotificationPreferenceService::class)->twoFactorSmsEnabled($owner);
    }

    private function deliverCode(User $user, string $method, string $code, CarbonInterface $expiresAt): array
    {
        if ($method === self::METHOD_SMS) {
            $phone = trim((string) $user->phone_number);
            if ($phone === '') {
                return [
                    'sent' => false,
                    'method' => self::METHOD_SMS,
                    'reason' => 'missing_phone',
                ];
            }

            $smsResult = app(SmsNotificationService::class)->sendWithResult($phone, $this->smsMessage($code, $expiresAt));
            if (!($smsResult['ok'] ?? false)) {
                return [
                    'sent' => false,
                    'method' => self::METHOD_SMS,
                    'reason' => (string) ($smsResult['reason'] ?? 'sms_failed'),
                ];
            }

            return [
                'sent' => true,
                'method' => self::METHOD_SMS,
            ];
        }

        try {
            $user->notify(new TwoFactorCodeNotification($code, Carbon::instance($expiresAt)));
        } catch (\Throwable) {
            return [
                'sent' => false,
                'method' => self::METHOD_EMAIL,
                'reason' => 'email_failed',
            ];
        }

        return [
            'sent' => true,
            'method' => self::METHOD_EMAIL,
        ];
    }

    private function smsMessage(string $code, CarbonInterface $expiresAt): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($expiresAt) / 60));
        $appName = (string) config('app.name', 'App');

        return "{$appName}: code de verification {$code}. Expire dans {$minutes} min.";
    }

    private function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;
        $code = (string) random_int(0, $max);

        return str_pad($code, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
