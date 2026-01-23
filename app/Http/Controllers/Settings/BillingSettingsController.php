<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AssistantCreditService;
use App\Models\AssistantUsage;
use App\Models\PlatformSetting;
use App\Services\BillingSubscriptionService;
use App\Services\StripeConnectService;
use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

class BillingSettingsController extends Controller
{
    private const AVAILABLE_METHODS = [
        ['id' => 'cash', 'name' => 'Cash'],
        ['id' => 'card', 'name' => 'Card'],
        ['id' => 'bank_transfer', 'name' => 'Bank transfer'],
        ['id' => 'check', 'name' => 'Check'],
    ];

    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $billingService = app(BillingSubscriptionService::class);
        $stripeBillingService = app(StripeBillingService::class);
        $providerRequested = $billingService->providerRequested();
        $providerEffective = $billingService->providerEffective();
        $isPaddleProvider = $billingService->isPaddle();
        $providerLabel = $billingService->providerLabel();
        $providerReady = $billingService->providerReady();

        $checkoutStatus = $request->query('checkout');
        $connectStatus = $request->query('connect');
        $creditStatus = $request->query('credits');

        $stripeConnectEnabled = (bool) config('services.stripe.connect_enabled');
        $stripeConnectReady = $stripeConnectEnabled
            && (bool) config('services.stripe.enabled')
            && (bool) config('services.stripe.secret');

        $paddleApiEnabled = $isPaddleProvider && (bool) config('cashier.api_key');
        $paddleJsEnabled = $isPaddleProvider && (bool) (config('cashier.client_side_token') || config('cashier.seller_id'));
        $paddleError = null;
        $retainKey = config('cashier.retain_key');
        $sellerId = config('cashier.seller_id');

        if ($isPaddleProvider && $paddleApiEnabled) {
            try {
                $user->createAsCustomer();
            } catch (\Throwable $exception) {
                $paddleError = 'Paddle API is not configured correctly.';
                Log::warning('Unable to create Paddle customer.', [
                    'user_id' => $user->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($isPaddleProvider && $checkoutStatus === 'success' && $paddleApiEnabled && !$paddleError) {
            try {
                $this->syncLatestSubscription($user);
            } catch (\Throwable $exception) {
                Log::warning('Unable to sync Paddle subscription after checkout.', [
                    'user_id' => $user->id,
                    'exception' => $exception->getMessage(),
                ]);
            }

            $user->unsetRelation('subscriptions');
        }

        if (!$isPaddleProvider && $providerEffective === 'stripe' && $checkoutStatus === 'success') {
            $sessionId = $request->query('session_id');
            if ($sessionId) {
                try {
                    app(StripeBillingService::class)->syncFromCheckoutSession($sessionId, $user);
                } catch (\Throwable $exception) {
                    Log::warning('Unable to sync Stripe subscription after checkout.', [
                        'user_id' => $user->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($providerEffective === 'stripe' && $creditStatus === 'success') {
            $sessionId = $request->query('session_id');
            if ($sessionId && app(StripeBillingService::class)->isConfigured()) {
                try {
                    $session = app(StripeBillingService::class)->retrieveCheckoutSession($sessionId);
                    if (is_array($session)) {
                        app(AssistantCreditService::class)->grantFromStripeSession($session, (int) config('services.stripe.ai_credit_pack', 0));
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Unable to sync assistant credit checkout session.', [
                        'user_id' => $user->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($stripeConnectReady && in_array($connectStatus, ['success', 'refresh'], true)) {
            try {
                app(StripeConnectService::class)->refreshAccountStatus($user);
                $user->refresh();
            } catch (\Throwable $exception) {
                Log::warning('Unable to refresh Stripe Connect account.', [
                    'user_id' => $user->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        $plans = collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? ucfirst($key),
                    'price_id' => $plan['price_id'] ?? null,
                    'price' => $plan['price'] ?? null,
                    'display_price' => $this->resolvePlanDisplayPrice($plan),
                    'features' => $plan['features'] ?? [],
                ];
            })
            ->values()
            ->all();

        $subscriptionSummary = $billingService->subscriptionSummary($user);
        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $billingService->resolvePlanKey($user, $planModules);
        $assistantIncluded = $planKey ? (bool) ($planModules[$planKey]['assistant'] ?? false) : false;
        $assistantEnabled = $user->hasCompanyFeature('assistant');
        $assistantAddonEnabled = $assistantEnabled && !$assistantIncluded;
        $assistantCreditPack = (int) config('services.stripe.ai_credit_pack', 0);
        $assistantCreditEnabled = $billingService->isStripe()
            && $providerReady
            && (bool) config('services.stripe.ai_credit_price')
            && $assistantCreditPack > 0
            && $stripeBillingService->isConfigured();
        $assistantUsageEnabled = $billingService->isStripe()
            && $providerReady
            && (bool) config('services.stripe.ai_usage_price')
            && $stripeBillingService->isConfigured();
        $assistantAddonAvailable = $assistantCreditEnabled || $assistantUsageEnabled;
        $assistantAddonMode = $assistantIncluded
            ? 'included'
            : ($assistantCreditEnabled ? 'credit' : ($assistantUsageEnabled ? 'metered' : 'none'));

        $usageStart = now()->startOfMonth();
        $usageEnd = now()->endOfMonth();
        $usageQuery = AssistantUsage::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$usageStart, $usageEnd]);
        $assistantUsage = [
            'requests' => (int) $usageQuery->sum('request_count'),
            'tokens' => (int) $usageQuery->sum('total_tokens'),
            'billed_units' => (int) $usageQuery->sum('billed_units'),
            'period_start' => $usageStart,
            'period_end' => $usageEnd,
        ];

        return $this->inertiaOrJson('Settings/Billing', [
            'billing' => [
                'provider' => $providerRequested,
                'provider_effective' => $providerEffective,
                'provider_label' => $providerLabel,
                'provider_ready' => $providerReady,
                'is_paddle' => $isPaddleProvider,
            ],
            'availableMethods' => self::AVAILABLE_METHODS,
            'paymentMethods' => array_values($user->payment_methods ?? []),
            'plans' => $plans,
            'subscription' => $subscriptionSummary,
            'assistantAddon' => [
                'included' => $assistantIncluded,
                'enabled' => $assistantEnabled,
                'addon_enabled' => $assistantAddonEnabled,
                'available' => $assistantAddonAvailable,
                'mode' => $assistantAddonMode,
                'usage' => $assistantUsage,
                'unit' => config('services.stripe.ai_usage_unit', 'requests'),
                'unit_size' => (int) config('services.stripe.ai_usage_unit_size', 1),
                'credits' => [
                    'enabled' => $assistantCreditEnabled && !$assistantIncluded,
                    'balance' => (int) ($user->assistant_credit_balance ?? 0),
                    'pack_size' => $assistantCreditPack,
                ],
            ],
            'checkoutStatus' => $checkoutStatus,
            'checkoutPlanKey' => $request->query('plan'),
            'creditStatus' => $creditStatus,
            'connectStatus' => $connectStatus,
            'paddle' => [
                'js_enabled' => $paddleJsEnabled,
                'api_enabled' => $paddleApiEnabled,
                'sandbox' => (bool) config('cashier.sandbox'),
                'customer_id' => $user->customer?->paddle_id,
                'client_side_token' => config('cashier.client_side_token'),
                'seller_id' => is_numeric($sellerId) ? (int) $sellerId : null,
                'retain_key' => is_numeric($retainKey) ? (int) $retainKey : null,
                'error' => $paddleError,
            ],
            'stripeConnect' => [
                'enabled' => $stripeConnectReady,
                'account_id' => $user->stripe_connect_account_id,
                'charges_enabled' => (bool) $user->stripe_connect_charges_enabled,
                'payouts_enabled' => (bool) $user->stripe_connect_payouts_enabled,
                'details_submitted' => (bool) $user->stripe_connect_details_submitted,
                'requirements' => $user->stripe_connect_requirements ?? [],
                'fee_percent' => (float) config('services.stripe.connect_fee_percent', 1.5),
            ],
        ]);
    }

    public function updateAssistantAddon(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $billingService = app(BillingSubscriptionService::class);
        if (!$billingService->isStripe()) {
            return response()->json([
                'message' => 'Assistant IA indisponible pour ce fournisseur.',
            ], 422);
        }

        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $billingService->resolvePlanKey($user, $planModules);
        $assistantIncluded = $planKey ? (bool) ($planModules[$planKey]['assistant'] ?? false) : false;
        if ($assistantIncluded) {
            return response()->json([
                'message' => 'Assistant IA deja inclus dans votre plan.',
            ], 422);
        }

        $creditPack = (int) config('services.stripe.ai_credit_pack', 0);
        $creditConfigured = (bool) config('services.stripe.ai_credit_price') && $creditPack > 0;
        $usageConfigured = (bool) config('services.stripe.ai_usage_price');
        if (!$creditConfigured && !$usageConfigured) {
            return response()->json([
                'message' => 'Assistant IA non configure.',
            ], 422);
        }

        $subscriptionSummary = $billingService->subscriptionSummary($user);
        if (empty($subscriptionSummary['price_id'])) {
            return response()->json([
                'message' => 'Aucun abonnement actif.',
            ], 422);
        }

        $stripeBilling = app(StripeBillingService::class);
        if (!$stripeBilling->isConfigured()) {
            return response()->json([
                'message' => 'Stripe n\'est pas configure.',
            ], 422);
        }

        $features = (array) ($user->company_features ?? []);
        $useUsageAddon = $usageConfigured && !$creditConfigured;
        if ($validated['enabled']) {
            if ($useUsageAddon) {
                $subscription = $stripeBilling->enableAssistantAddon($user);
                if (!$subscription) {
                    return response()->json([
                        'message' => 'Impossible d\'activer l\'Assistant IA.',
                    ], 422);
                }
            }
            $features['assistant'] = true;
        } else {
            if ($useUsageAddon) {
                $stripeBilling->disableAssistantAddon($user);
            }
            $features['assistant'] = false;
        }

        $user->update([
            'company_features' => $features,
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Assistant IA mis a jour.',
                'enabled' => (bool) $features['assistant'],
            ]);
        }

        return redirect()->back()->with('success', 'Assistant IA mis a jour.');
    }

    public function createAssistantCreditCheckout(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $validated = $request->validate([
            'packs' => 'nullable|integer|min:1|max:50',
        ]);

        $billingService = app(BillingSubscriptionService::class);
        if (!$billingService->isStripe()) {
            return response()->json([
                'message' => 'Assistant IA indisponible pour ce fournisseur.',
            ], 422);
        }

        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $billingService->resolvePlanKey($user, $planModules);
        $assistantIncluded = $planKey ? (bool) ($planModules[$planKey]['assistant'] ?? false) : false;
        if ($assistantIncluded) {
            return response()->json([
                'message' => 'Assistant IA deja inclus dans votre plan.',
            ], 422);
        }

        if (!$user->hasCompanyFeature('assistant')) {
            return response()->json([
                'message' => 'Activez l option IA avant d acheter des credits.',
            ], 422);
        }

        if (!config('services.stripe.ai_credit_price')) {
            return response()->json([
                'message' => 'Prix de credits IA manquant.',
            ], 422);
        }

        $packSize = (int) config('services.stripe.ai_credit_pack', 0);
        if ($packSize <= 0) {
            return response()->json([
                'message' => 'Pack de credits IA non configure.',
            ], 422);
        }

        $subscriptionSummary = $billingService->subscriptionSummary($user);
        if (empty($subscriptionSummary['price_id'])) {
            return response()->json([
                'message' => 'Aucun abonnement actif.',
            ], 422);
        }

        $stripeBilling = app(StripeBillingService::class);
        if (!$stripeBilling->isConfigured()) {
            return response()->json([
                'message' => 'Stripe n\'est pas configure.',
            ], 422);
        }

        $packs = (int) ($validated['packs'] ?? 1);
        $successUrl = route('settings.billing.edit', ['credits' => 'success']);
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('settings.billing.edit', ['credits' => 'cancel']);

        $session = $stripeBilling->createAssistantCreditCheckoutSession($user, $packs, $successUrl, $cancelUrl);
        if (empty($session['url'])) {
            return response()->json([
                'message' => 'Impossible de demarrer le checkout credits.',
            ], 422);
        }

        return response()->json(['url' => $session['url']]);
    }

    public function connectStripe(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $connectService = app(StripeConnectService::class);
        if (!$connectService->isEnabled()) {
            return response()->json([
                'message' => 'Stripe Connect is not configured.',
            ], 400);
        }

        $refreshUrl = route('settings.billing.edit', ['connect' => 'refresh']);
        $returnUrl = route('settings.billing.edit', ['connect' => 'success']);

        try {
            $url = $connectService->createOnboardingLink($user, $refreshUrl, $returnUrl);
        } catch (\Throwable $exception) {
            Log::warning('Unable to start Stripe Connect onboarding.', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);
            $url = null;
        }

        if (!$url) {
            return response()->json([
                'message' => 'Unable to start Stripe Connect onboarding.',
            ], 422);
        }

        return response()->json(['url' => $url]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $allowed = collect(self::AVAILABLE_METHODS)->pluck('id')->all();

        $validated = $request->validate([
            'payment_methods' => 'nullable|array',
            'payment_methods.*' => ['string', Rule::in($allowed)],
        ]);

        $user->update([
            'payment_methods' => array_values(array_unique($validated['payment_methods'] ?? [])),
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Payment settings updated.',
                'payment_methods' => $user->payment_methods,
            ]);
        }

        return redirect()->back()->with('success', 'Payment settings updated.');
    }

    private function resolvePlanDisplayPrice(array $plan): ?string
    {
        $raw = $plan['price'] ?? null;
        $rawValue = is_string($raw) ? trim($raw) : $raw;

        if (is_numeric($rawValue)) {
            return Cashier::formatAmount((int) round((float) $rawValue * 100), config('cashier.currency', 'USD'));
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }

    private function syncLatestSubscription($user): void
    {
        $customer = $user->customer ?: $user->createAsCustomer();
        if (!$customer) {
            return;
        }

        $latest = Cashier::api('GET', 'subscriptions', [
            'customer_id' => $customer->paddle_id,
            'per_page' => 1,
            'status' => implode(',', [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_PAST_DUE,
                Subscription::STATUS_PAUSED,
                Subscription::STATUS_CANCELED,
            ]),
        ])['data'][0] ?? null;

        if (!$latest || empty($latest['id'])) {
            return;
        }

        $subscription = $user->subscriptions()->firstOrNew([
            'paddle_id' => $latest['id'],
        ]);

        $subscription->type = $latest['custom_data']['subscription_type'] ?? Subscription::DEFAULT_TYPE;
        $subscription->status = $latest['status'] ?? Subscription::STATUS_ACTIVE;
        $subscription->trial_ends_at = ($subscription->status === Subscription::STATUS_TRIALING && !empty($latest['next_billed_at']))
            ? Carbon::parse($latest['next_billed_at'], 'UTC')
            : null;

        $subscription->paused_at = !empty($latest['paused_at'])
            ? Carbon::parse($latest['paused_at'], 'UTC')
            : null;

        $subscription->ends_at = !empty($latest['canceled_at'])
            ? Carbon::parse($latest['canceled_at'], 'UTC')
            : null;

        $subscription->save();

        $items = $latest['items'] ?? [];
        $knownPriceIds = [];
        foreach ($items as $item) {
            $priceId = $item['price']['id'] ?? null;
            if (!$priceId) {
                continue;
            }

            $knownPriceIds[] = $priceId;

            $subscription->items()->updateOrCreate([
                'subscription_id' => $subscription->id,
                'price_id' => $priceId,
            ], [
                'product_id' => $item['price']['product_id'] ?? '',
                'status' => $item['status'] ?? Subscription::STATUS_ACTIVE,
                'quantity' => $item['quantity'] ?? 1,
            ]);
        }

        if ($knownPriceIds) {
            $subscription->items()->whereNotIn('price_id', $knownPriceIds)->delete();
        }

        $user->customer?->update(['trial_ends_at' => null]);
    }
}
