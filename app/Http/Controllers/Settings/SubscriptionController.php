<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BillingMutationErrorCode;
use App\Enums\BillingPeriod;
use App\Http\Controllers\Concerns\InteractsWithBillingMutationResponses;
use App\Http\Controllers\Controller;
use App\Services\BillingPlanService;
use App\Services\BillingSubscriptionService;
use App\Services\CreateStripeSubscriptionForTenant;
use App\Services\PlatformAdminNotifier;
use App\Services\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Paddle\Subscription;

class SubscriptionController extends Controller
{
    use InteractsWithBillingMutationResponses;

    public function portal(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }
        $billingService = app(BillingSubscriptionService::class);
        if ($billingService->isStripe()) {
            $portalUrl = app(StripeBillingService::class)->createPortalSession($user, route('settings.billing.edit'));
            if (! $portalUrl) {
                if ($this->shouldReturnJson($request)) {
                    return $this->billingErrorResponse(
                        'Unable to open Stripe customer portal.',
                        BillingMutationErrorCode::PortalUnavailable
                    );
                }

                return redirect()->back()->with('error', 'Unable to open Stripe customer portal.');
            }

            if ($this->shouldReturnJson($request)) {
                return $this->billingActionResponse('requires_redirect', 'open_customer_portal', [
                    'url' => $portalUrl,
                ]);
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location($portalUrl);
            }

            return redirect()->away($portalUrl);
        }

        if ($response = $this->denyIfNotPaddle($request)) {
            return $response;
        }

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        if (! $subscription) {
            if ($this->shouldReturnJson($request)) {
                return $this->billingErrorResponse(
                    'No active subscription found.',
                    BillingMutationErrorCode::SubscriptionRequired
                );
            }

            return redirect()->back()->with('error', 'No active subscription found.');
        }

        $updateUrl = $subscription->paymentMethodUpdateUrl();

        if ($this->shouldReturnJson($request)) {
            return $this->billingActionResponse('requires_redirect', 'open_payment_method_update', [
                'url' => $updateUrl,
            ]);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($updateUrl);
        }

        return redirect()->away($updateUrl);
    }

    public function paymentMethodTransaction(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }
        if (! $this->isPaddleProvider()) {
            return $this->billingErrorResponse(
                'Billing provider is not Paddle.',
                BillingMutationErrorCode::ProviderNotPaddle
            );
        }

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        if (! $subscription) {
            return $this->billingErrorResponse(
                'No subscription found.',
                BillingMutationErrorCode::SubscriptionRequired
            );
        }

        try {
            $transaction = $subscription->paymentMethodUpdateTransaction();
        } catch (\Throwable $exception) {
            Log::warning('Unable to create Paddle payment method update transaction.', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);

            return $this->billingErrorResponse(
                'Unable to start payment method update.',
                BillingMutationErrorCode::PaymentMethodUpdateFailed,
                500
            );
        }

        $transactionId = $transaction['id'] ?? $transaction['transaction_id'] ?? null;
        if (! $transactionId) {
            return $this->billingErrorResponse(
                'Invalid Paddle transaction response.',
                BillingMutationErrorCode::InvalidProviderResponse,
                500
            );
        }

        return $this->billingActionResponse('ready', 'present_payment_method_update', [
            'transaction_id' => $transactionId,
        ]);
    }

    public function swap(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }
        $billingService = app(BillingSubscriptionService::class);

        $plans = $billingService->isStripe()
            ? collect(app(BillingPlanService::class)->plansForTenant($user))
                ->filter(fn (array $plan) => collect($plan['prices_by_period'] ?? [])
                    ->pluck('stripe_price_id')
                    ->filter()
                    ->isNotEmpty())
                ->values()
            : collect(config('billing.plans', []))
                ->map(fn (array $plan, string $key) => array_merge(['key' => $key], $plan))
                ->filter(fn (array $plan) => ! empty($plan['price_id']))
                ->values();

        $priceIds = $billingService->isStripe()
            ? $this->stripePlanPriceIds($plans)
            : $plans->pluck('price_id')->filter()->values()->all();
        $planKeys = $plans->pluck('key')->filter()->values()->all();
        if (! $priceIds) {
            if ($this->shouldReturnJson($request)) {
                return $this->billingErrorResponse(
                    'No subscription plans are configured.',
                    BillingMutationErrorCode::PlansNotConfigured,
                    422,
                    [
                        'errors' => [
                            'price_id' => ['No subscription plans are configured.'],
                        ],
                    ]
                );
            }

            return redirect()->back()->withErrors([
                'price_id' => 'No subscription plans are configured.',
            ]);
        }

        $validated = $request->validate([
            'plan_key' => ['nullable', Rule::in($planKeys)],
            'price_id' => ['nullable', Rule::in($priceIds)],
            'billing_period' => ['nullable', Rule::in(BillingPeriod::values())],
            'success_url' => 'nullable|string|max:2048',
            'cancel_url' => 'nullable|string|max:2048',
        ]);

        $selectedBillingPeriod = BillingPeriod::default();
        $plan = null;
        $selectedPriceId = null;
        $planKey = null;

        if ($billingService->isStripe()) {
            $selection = $this->resolveStripePlanSelection($plans, $validated);
            $plan = $selection['plan'];
            $selectedPriceId = $selection['selected_price_id'];
            $planKey = $selection['plan_key'];
            $selectedBillingPeriod = $selection['billing_period'];
        } else {
            $plan = ! empty($validated['plan_key'])
                ? $plans->firstWhere('key', $validated['plan_key'])
                : $plans->firstWhere('price_id', $validated['price_id'] ?? null);
            $selectedPriceId = $plan['price_id'] ?? null;
            $planKey = $plan['key'] ?? null;
        }

        if (! $selectedPriceId || ! $planKey) {
            if ($this->shouldReturnJson($request)) {
                return $this->billingErrorResponse(
                    'Please choose a valid subscription plan.',
                    BillingMutationErrorCode::InvalidPlanSelection,
                    422,
                    [
                        'errors' => [
                            'plan_key' => ['Please choose a valid subscription plan.'],
                        ],
                    ]
                );
            }

            return redirect()->back()->withErrors([
                'plan_key' => 'Please choose a valid subscription plan.',
            ]);
        }
        $currentPriceId = $billingService->resolvePriceId($user);

        if ($selectedPriceId && $currentPriceId === $selectedPriceId) {
            if ($this->shouldReturnJson($request)) {
                return $this->billingNoopResponse(
                    'You are already on this plan.',
                    BillingMutationErrorCode::PlanUnchanged
                );
            }

            return redirect()->back()->with('info', 'You are already on this plan.');
        }

        if ($billingService->isStripe()) {
            $summary = $billingService->subscriptionSummary($user);
            if (! $summary['active']) {
                if ($this->shouldReturnJson($request)) {
                    return $this->billingErrorResponse(
                        'You do not have an active subscription.',
                        BillingMutationErrorCode::SubscriptionRequired,
                        422,
                        [
                            'errors' => [
                                'price_id' => ['You do not have an active subscription.'],
                            ],
                        ]
                    );
                }

                return redirect()->back()->withErrors([
                    'price_id' => 'You do not have an active subscription.',
                ]);
            }

            try {
                $eligibilityErrors = $planKey
                    ? $billingService->ownerOnlyPlanSelectionErrors($user, $planKey, (int) ($user->company_team_size ?? 0))
                    : [];
                if ($eligibilityErrors !== []) {
                    if ($this->shouldReturnJson($request)) {
                        return $this->billingErrorResponse(
                            $eligibilityErrors[0],
                            BillingMutationErrorCode::PlanRestricted,
                            422,
                            [
                                'errors' => [
                                    'plan_key' => $eligibilityErrors,
                                ],
                            ]
                        );
                    }

                    return redirect()->back()->withErrors([
                        'plan_key' => $eligibilityErrors[0],
                    ]);
                }

                $seatQuantity = $billingService->resolveBillableQuantity($user, $planKey);
                $updated = app(CreateStripeSubscriptionForTenant::class)->swap(
                    $user,
                    (string) $planKey,
                    $seatQuantity,
                    $selectedBillingPeriod
                );
                if (! $updated) {
                    throw new \RuntimeException('Stripe subscription update failed.');
                }
            } catch (\Throwable $exception) {
                if ($this->shouldReturnJson($request)) {
                    return $this->billingErrorResponse(
                        'Unable to change plans right now.',
                        BillingMutationErrorCode::MutationFailed,
                        500
                    );
                }

                return redirect()->back()->with('error', 'Unable to change plans right now.');
            }
        } else {
            if ($response = $this->denyIfNotPaddle($request)) {
                return $response;
            }

            $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
            if (! $subscription || ! $subscription->active()) {
                if ($this->shouldReturnJson($request)) {
                    return $this->billingErrorResponse(
                        'You do not have an active subscription.',
                        BillingMutationErrorCode::SubscriptionRequired,
                        422,
                        [
                            'errors' => [
                                'price_id' => ['You do not have an active subscription.'],
                            ],
                        ]
                    );
                }

                return redirect()->back()->withErrors([
                    'price_id' => 'You do not have an active subscription.',
                ]);
            }

            try {
                $subscription->swap((string) $selectedPriceId);
            } catch (\Throwable $exception) {
                if ($this->shouldReturnJson($request)) {
                    return $this->billingErrorResponse(
                        'Unable to change plans right now.',
                        BillingMutationErrorCode::MutationFailed,
                        500
                    );
                }

                return redirect()->back()->with('error', 'Unable to change plans right now.');
            }
        }

        $notifier = app(PlatformAdminNotifier::class);
        $notifier->notify('plan_changed', 'Plan changed', [
            'intro' => ($user->company_name ?: $user->email).' changed their plan.',
            'details' => [
                ['label' => 'Company', 'value' => $user->company_name ?: 'Not set'],
                ['label' => 'Owner', 'value' => $user->email ?: 'Unknown'],
                ['label' => 'From', 'value' => $notifier->resolvePlanName($currentPriceId)],
                ['label' => 'To', 'value' => $notifier->resolvePlanName($selectedPriceId)],
            ],
            'actionUrl' => route('superadmin.tenants.show', $user->id),
            'actionLabel' => 'View tenant',
            'reference' => 'plan:'.$user->id.':'.$currentPriceId.':'.$selectedPriceId,
            'severity' => 'info',
        ]);

        if ($this->shouldReturnJson($request)) {
            return $this->billingActionResponse('success', 'subscription_updated', [
                'message' => 'Plan updated.',
                'plan_key' => $planKey,
                'billing_period' => $selectedBillingPeriod->value,
                'resolved_plan' => $this->buildResolvedPlanPayload($plan, $selectedBillingPeriod),
            ]);
        }

        return redirect()->route('settings.billing.edit', array_filter([
            'checkout' => 'swapped',
            'plan' => $planKey,
            'billing_period' => $selectedBillingPeriod->value,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isAccountOwner()) {
            abort(403);
        }

        $billingService = app(BillingSubscriptionService::class);
        if (! $billingService->isStripe()) {
            return $this->billingErrorResponse(
                'Billing provider is not Stripe.',
                BillingMutationErrorCode::ProviderNotStripe
            );
        }
        if (! $billingService->providerReady()) {
            return $this->billingErrorResponse(
                'Stripe is not configured.',
                BillingMutationErrorCode::ProviderNotConfigured
            );
        }

        $plans = collect(app(BillingPlanService::class)->plansForTenant($user))
            ->filter(fn (array $plan) => collect($plan['prices_by_period'] ?? [])
                ->pluck('stripe_price_id')
                ->filter()
                ->isNotEmpty())
            ->values();

        $priceIds = $this->stripePlanPriceIds($plans);
        $planKeys = $plans->pluck('key')->filter()->values()->all();
        $validated = $request->validate([
            'plan_key' => ['nullable', Rule::in($planKeys)],
            'price_id' => ['nullable', Rule::in($priceIds)],
            'billing_period' => ['nullable', Rule::in(BillingPeriod::values())],
            'success_url' => 'nullable|string|max:2048',
            'cancel_url' => 'nullable|string|max:2048',
        ]);

        $selection = $this->resolveStripePlanSelection($plans, $validated);
        $planKey = $selection['plan_key'];
        $selectedBillingPeriod = $selection['billing_period'];
        $selectedPriceId = $selection['selected_price_id'];

        if (! $planKey || ! $selectedPriceId) {
            return $this->billingErrorResponse(
                'Please choose a valid subscription plan.',
                BillingMutationErrorCode::InvalidPlanSelection,
                422,
                [
                    'errors' => [
                        'plan_key' => ['Please choose a valid subscription plan.'],
                    ],
                ]
            );
        }
        $eligibilityErrors = $planKey
            ? $billingService->ownerOnlyPlanSelectionErrors($user, $planKey, (int) ($user->company_team_size ?? 0))
            : [];

        if ($eligibilityErrors !== []) {
            return $this->billingErrorResponse(
                $eligibilityErrors[0],
                BillingMutationErrorCode::PlanRestricted,
                422,
                [
                    'errors' => [
                        'plan_key' => $eligibilityErrors,
                    ],
                ]
            );
        }

        $successUrl = $this->resolveRequestedReturnUrl(
            $validated['success_url'] ?? null,
            route('settings.billing.edit', array_filter([
                'checkout' => 'success',
                'plan' => $planKey,
                'billing_period' => $selectedBillingPeriod->value,
            ], fn ($value) => $value !== null && $value !== ''))
        );
        $successUrl = $this->appendQueryParameterIfMissing($successUrl, 'session_id', '{CHECKOUT_SESSION_ID}');

        $cancelUrl = $this->resolveRequestedReturnUrl(
            $validated['cancel_url'] ?? null,
            route('settings.billing.edit', ['checkout' => 'cancel'])
        );

        try {
            $seatQuantity = $billingService->resolveBillableQuantity($user, $planKey);
            $session = app(CreateStripeSubscriptionForTenant::class)->checkoutSession(
                $user,
                (string) $planKey,
                $successUrl,
                $cancelUrl,
                $seatQuantity,
                null,
                $selectedBillingPeriod
            );
        } catch (\Throwable $exception) {
            Log::error('Stripe checkout session creation failed.', [
                'user_id' => $user->id,
                'plan_key' => $planKey,
                'message' => $exception->getMessage(),
            ]);

            return $this->billingErrorResponse(
                $exception->getMessage() ?: 'Unable to start Stripe checkout.',
                BillingMutationErrorCode::MutationFailed,
                500
            );
        }

        if (empty($session['url'])) {
            return $this->billingErrorResponse(
                'Stripe checkout did not return a URL.',
                BillingMutationErrorCode::InvalidProviderResponse,
                500
            );
        }

        return $this->billingActionResponse('requires_redirect', 'open_checkout', [
            'url' => $session['url'],
            'resolved_plan' => $this->buildResolvedPlanPayload($selection['plan'], $selectedBillingPeriod),
            'return_urls' => [
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ],
        ]);
    }

    private function denyIfNotPaddle(Request $request)
    {
        if ($this->isPaddleProvider()) {
            return null;
        }

        $message = 'Billing provider is not Paddle.';
        if ($this->shouldReturnJson($request)) {
            return $this->billingErrorResponse(
                $message,
                BillingMutationErrorCode::ProviderNotPaddle
            );
        }

        return redirect()->back()->with('error', $message);
    }

    private function isPaddleProvider(): bool
    {
        $provider = strtolower((string) config('billing.provider_effective', config('billing.provider', 'paddle')));

        return $provider === '' || $provider === 'paddle';
    }

    private function stripePlanPriceIds(Collection $plans): array
    {
        return $plans
            ->flatMap(function (array $plan) {
                return collect($plan['prices_by_period'] ?? [])
                    ->pluck('stripe_price_id')
                    ->filter();
            })
            ->values()
            ->all();
    }

    private function resolveStripePlanSelection(Collection $plans, array $validated): array
    {
        $selectedBillingPeriod = BillingPeriod::tryFromMixed($validated['billing_period'] ?? null) ?? BillingPeriod::default();
        $plan = null;

        if (! empty($validated['plan_key'])) {
            $plan = $plans->firstWhere('key', $validated['plan_key']);
        } elseif (! empty($validated['price_id'])) {
            $priceId = $validated['price_id'];
            $plan = $plans->first(function (array $candidate) use ($priceId, &$selectedBillingPeriod): bool {
                foreach (($candidate['prices_by_period'] ?? []) as $period => $price) {
                    if (($price['stripe_price_id'] ?? null) !== $priceId) {
                        continue;
                    }

                    $selectedBillingPeriod = BillingPeriod::tryFromMixed($period) ?? BillingPeriod::default();

                    return true;
                }

                return false;
            });
        }

        $selectedPrice = is_array($plan['prices_by_period'] ?? null)
            ? ($plan['prices_by_period'][$selectedBillingPeriod->value] ?? null)
            : null;

        return [
            'plan' => $plan,
            'plan_key' => $plan['key'] ?? null,
            'billing_period' => $selectedBillingPeriod,
            'selected_price_id' => $selectedPrice['stripe_price_id'] ?? null,
            'selected_price' => $selectedPrice,
        ];
    }

    private function buildResolvedPlanPayload(?array $plan, BillingPeriod $billingPeriod): array
    {
        if (! is_array($plan)) {
            return [
                'plan_key' => null,
                'billing_period' => $billingPeriod->value,
                'currency_code' => null,
                'promotion_discount_percent' => null,
            ];
        }

        $selectedPrice = $plan['prices_by_period'][$billingPeriod->value] ?? null;
        $promotionDiscountPercent = data_get($selectedPrice, 'promotion.discount_percent')
            ?? data_get($plan, 'promotion.discount_percent');

        return [
            'plan_key' => $plan['key'] ?? null,
            'billing_period' => $billingPeriod->value,
            'currency_code' => data_get($selectedPrice, 'currency_code') ?? ($plan['currency_code'] ?? null),
            'promotion_discount_percent' => is_numeric($promotionDiscountPercent)
                ? (int) $promotionDiscountPercent
                : null,
        ];
    }

    private function resolveRequestedReturnUrl(?string $requestedUrl, string $fallbackUrl): string
    {
        $normalized = is_string($requestedUrl) ? trim($requestedUrl) : '';

        return $normalized !== '' ? $normalized : $fallbackUrl;
    }

    private function appendQueryParameterIfMissing(string $url, string $key, string $value): string
    {
        if (str_contains($url, $key.'=')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$key.'='.$value;
    }
}
