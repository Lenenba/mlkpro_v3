<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\SecurityEventService;
use App\Services\TotpService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TwoFactorController extends AuthController
{
    private const CHALLENGE_PREFIX = 'two-factor-challenge:';

    private function resolveChallenge(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $data = Cache::get(self::CHALLENGE_PREFIX . $token);
        return is_array($data) ? $data : null;
    }

    private function buildDeviceName(Request $request, ?string $fallback = null): string
    {
        $deviceName = $fallback ?? '';
        if ($deviceName === '') {
            $deviceName = trim((string) $request->userAgent());
        }
        if ($deviceName === '') {
            $deviceName = 'mobile';
        }
        return Str::limit($deviceName, 80, '');
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => 'required|string',
            'code' => 'required|string|min:4|max:10',
        ]);

        $challenge = $this->resolveChallenge($validated['challenge_token']);
        if (!$challenge || empty($challenge['user_id'])) {
            return response()->json(['message' => 'Two-factor session expired.'], 422);
        }

        $user = User::query()->find($challenge['user_id']);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->isSuspended()) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        $limiterKey = 'two-factor-verify:' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            return response()->json([
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $twoFactorService = app(TwoFactorService::class);
        $method = (string) ($challenge['method'] ?? $user->twoFactorMethod());
        $effectiveMethod = $twoFactorService->resolveEffectiveMethod($user, $method);
        $code = trim($validated['code']);

        $verified = false;
        if ($effectiveMethod === TwoFactorService::METHOD_APP && !empty($user->two_factor_secret)) {
            $verified = app(TotpService::class)->verifyCode($user->two_factor_secret, $code);
        } else {
            $verified = $twoFactorService->verifyCode($user, $code);
        }

        if (!$verified) {
            RateLimiter::hit($limiterKey);
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        if ($effectiveMethod === TwoFactorService::METHOD_APP && !$user->two_factor_enabled) {
            $user->forceFill(['two_factor_enabled' => true])->save();
        }

        RateLimiter::clear($limiterKey);
        Cache::forget(self::CHALLENGE_PREFIX . $validated['challenge_token']);

        $deviceName = $this->buildDeviceName($request, $challenge['device_name'] ?? null);
        $user->loadMissing(['role', 'platformAdmin', 'teamMembership']);
        $token = $user->createToken($deviceName);

        app(SecurityEventService::class)->record($user, 'auth.login', $request, [
            'channel' => 'api',
            'device' => $deviceName,
            'two_factor' => true,
        ]);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $token->plainTextToken,
            'user' => $user,
            'meta' => $this->buildMeta($user),
        ]);
    }

    public function resend(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => 'required|string',
        ]);

        $challenge = $this->resolveChallenge($validated['challenge_token']);
        if (!$challenge || empty($challenge['user_id'])) {
            return response()->json(['message' => 'Two-factor session expired.'], 422);
        }

        $user = User::query()->find($challenge['user_id']);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->isSuspended()) {
            return response()->json(['message' => 'Account suspended.'], 403);
        }

        $twoFactorService = app(TwoFactorService::class);
        $method = (string) ($challenge['method'] ?? $user->twoFactorMethod());
        $effectiveMethod = $twoFactorService->resolveEffectiveMethod($user, $method);
        if ($effectiveMethod === TwoFactorService::METHOD_APP) {
            return response()->json(['message' => 'App-based codes cannot be resent.'], 422);
        }

        $limiterKey = 'two-factor-resend:' . $user->id . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            return response()->json([
                'message' => "Please wait {$seconds} seconds before requesting a new code.",
                'retry_after' => $seconds,
            ], 429);
        }

        $result = $twoFactorService->sendCode($user, false, $effectiveMethod);
        if (!$result['sent']) {
            if (($result['reason'] ?? null) !== 'cooldown') {
                return response()->json([
                    'message' => 'Unable to deliver a new code right now.',
                ], 422);
            }

            return response()->json([
                'message' => "Please wait {$result['retry_after']} seconds before requesting a new code.",
                'retry_after' => $result['retry_after'],
            ], 429);
        }

        RateLimiter::hit($limiterKey);

        app(SecurityEventService::class)->record($user, 'auth.2fa.resend', $request, [
            'channel' => 'api',
            'method' => $result['method'] ?? $effectiveMethod,
        ]);

        $challenge['method'] = $result['method'] ?? $effectiveMethod;
        Cache::put(self::CHALLENGE_PREFIX . $validated['challenge_token'], $challenge, now()->addMinutes(15));

        $responseMethod = (string) ($challenge['method'] ?? $effectiveMethod);
        return response()->json([
            'two_factor' => [
                'challenge_token' => $validated['challenge_token'],
                'method' => $responseMethod,
                'expires_at' => $result['expires_at']?->toIso8601String(),
                'retry_after' => $result['retry_after'] ?? 0,
                'phone_hint' => $responseMethod === TwoFactorService::METHOD_SMS
                    ? $twoFactorService->maskedPhoneNumber($user->phone_number)
                    : null,
            ],
        ]);
    }
}
