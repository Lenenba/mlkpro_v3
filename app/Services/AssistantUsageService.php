<?php

namespace App\Services;

use App\Models\AssistantUsage;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AssistantUsageService
{
    public function record(User $user, array $usage, ?string $model = null, string $provider = 'openai'): AssistantUsage
    {
        $owner = $this->resolveOwner($user);
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));

        $billedUnits = $this->resolveBilledUnits($totalTokens);
        $record = AssistantUsage::create([
            'user_id' => $owner->id,
            'request_count' => 1,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'billed_units' => $billedUnits,
            'model' => $model,
            'provider' => $provider,
        ]);

        if ($this->shouldSendUsage($owner)) {
            $this->syncStripeUsage($owner, $record);
        }

        return $record;
    }

    private function resolveOwner(User $user): User
    {
        $ownerId = $user->accountOwnerId();
        if ($ownerId === $user->id) {
            return $user;
        }

        return User::query()->find($ownerId) ?? $user;
    }

    private function resolveBilledUnits(int $totalTokens): int
    {
        $unit = strtolower((string) config('services.stripe.ai_usage_unit', 'requests'));
        $unitSize = (int) config('services.stripe.ai_usage_unit_size', 1);
        $unitSize = $unitSize > 0 ? $unitSize : 1;

        if ($unit === 'tokens') {
            return max(1, (int) ceil($totalTokens / $unitSize));
        }

        return 1;
    }

    private function shouldSendUsage(User $owner): bool
    {
        $billingService = app(BillingSubscriptionService::class);
        if (!$billingService->isStripe()) {
            return false;
        }

        if (!config('services.stripe.ai_usage_price')) {
            return false;
        }

        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $billingService->resolvePlanKey($owner, $planModules);
        $assistantIncluded = $planKey ? (bool) ($planModules[$planKey]['assistant'] ?? false) : false;
        if ($assistantIncluded) {
            return false;
        }

        return $owner->hasCompanyFeature('assistant');
    }

    private function syncStripeUsage(User $owner, AssistantUsage $record): void
    {
        $stripeService = app(StripeBillingService::class);
        if (!$stripeService->isConfigured()) {
            return;
        }

        try {
            $usageId = $stripeService->recordAssistantUsage($owner, $record->billed_units, Carbon::parse($record->created_at)->timestamp);
            if ($usageId) {
                $record->update([
                    'stripe_usage_id' => $usageId,
                    'stripe_item_id' => $stripeService->resolveAssistantItemId($owner),
                    'stripe_synced_at' => now(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to sync assistant usage to Stripe.', [
                'user_id' => $owner->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
