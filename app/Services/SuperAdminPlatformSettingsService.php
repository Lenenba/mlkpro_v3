<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\PlanDisplay;
use Illuminate\Validation\Rule;

class SuperAdminPlatformSettingsService
{
    private array $limitKeys = [
        'quotes',
        'requests',
        'plan_scan_quotes',
        'invoices',
        'jobs',
        'products',
        'services',
        'tasks',
        'team_members',
        'assistant_requests',
    ];

    private array $moduleKeys = [
        'quotes',
        'requests',
        'reservations',
        'plan_scans',
        'invoices',
        'jobs',
        'products',
        'performance',
        'presence',
        'planning',
        'expenses',
        'services',
        'tasks',
        'team_members',
        'assistant',
        'loyalty',
        'campaigns',
    ];

    public function formPayload(): array
    {
        $rawPlans = config('billing.plans', []);
        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planModules = PlatformSetting::getValue('plan_modules', []);

        $plans = collect($rawPlans)
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? $key,
                    'price_id' => $plan['price_id'] ?? null,
                ];
            })
            ->values()
            ->all();

        $planKeys = array_keys($rawPlans);
        if ($planKeys === []) {
            $planKeys = array_values(array_unique(array_merge(array_keys($planLimits), array_keys($planModules))));
        }

        foreach ($planKeys as $planKey) {
            $limitInput = $planLimits[$planKey] ?? [];
            foreach ($this->limitKeys as $limitKey) {
                $planLimits[$planKey][$limitKey] = array_key_exists($limitKey, $limitInput)
                    ? $limitInput[$limitKey]
                    : null;
            }

            $moduleInput = $planModules[$planKey] ?? [];
            foreach ($this->moduleKeys as $moduleKey) {
                $planModules[$planKey][$moduleKey] = array_key_exists($moduleKey, $moduleInput)
                    ? (bool) $moduleInput[$moduleKey]
                    : true;
            }
        }

        return [
            'maintenance' => PlatformSetting::getValue('maintenance', [
                'enabled' => false,
                'message' => '',
            ]),
            'templates' => PlatformSetting::getValue('templates', [
                'email_default' => '',
                'quote_default' => '',
                'invoice_default' => '',
            ]),
            'public_navigation' => PlatformSetting::getValue('public_navigation', [
                'contact_form_url' => '',
            ]),
            'plans' => $plans,
            'plan_prices' => app(BillingPlanService::class)->priceMatrix(),
            'plan_limits' => $planLimits,
            'plan_modules' => $planModules,
            'plan_display' => PlanDisplay::normalize(
                $rawPlans,
                PlatformSetting::getValue('plan_display', [])
            ),
            'subscription_promotion' => app(SubscriptionPromotionService::class)->adminPayload(),
            'promotion_discount_options' => app(SubscriptionPromotionService::class)->allowedDiscountPercents(),
            'annual_discount_percent' => app(BillingPlanService::class)->annualDiscountPercent(),
        ];
    }

    public function validationRules(): array
    {
        $discountOptions = app(SubscriptionPromotionService::class)->allowedDiscountPercents();

        return [
            'maintenance.enabled' => 'required|boolean',
            'maintenance.message' => 'nullable|string|max:500',
            'templates.email_default' => 'nullable|string|max:5000',
            'templates.quote_default' => 'nullable|string|max:5000',
            'templates.invoice_default' => 'nullable|string|max:5000',
            'public_navigation.contact_form_url' => 'nullable|string|max:2048',
            'plan_limits' => 'nullable|array',
            'plan_limits.*' => 'array',
            'plan_limits.*.*' => 'nullable|numeric|min:0',
            'plan_modules' => 'nullable|array',
            'plan_modules.*' => 'array',
            'plan_modules.*.*' => 'nullable|boolean',
            'plan_display' => 'nullable|array',
            'plan_display.*' => 'array',
            'plan_display.*.name' => 'nullable|string|max:120',
            'plan_display.*.price' => 'nullable',
            'plan_display.*.badge' => 'nullable|string|max:40',
            'plan_display.*.features' => 'nullable|array',
            'plan_display.*.features.*' => 'nullable|string|max:140',
            'plan_prices' => 'nullable|array',
            'plan_prices.*' => 'array',
            'plan_prices.*.*.amount' => 'nullable|numeric|min:0',
            'plan_prices.*.*.stripe_price_id' => 'nullable|string|max:255',
            'plan_prices.*.*.currency_code' => 'nullable|string|size:3',
            'plan_prices.*.*.billing_period' => 'nullable|string|max:20',
            'plan_prices.*.*.is_active' => 'nullable|boolean',
            'subscription_promotion' => 'nullable|array',
            'subscription_promotion.enabled' => 'required_with:subscription_promotion|boolean',
            'subscription_promotion.monthly_discount_percent' => [
                'nullable',
                'integer',
                Rule::in($discountOptions),
                Rule::requiredIf(fn () => $this->promotionNeedsBillingPeriodDiscount('yearly_discount_percent')),
            ],
            'subscription_promotion.yearly_discount_percent' => [
                'nullable',
                'integer',
                Rule::in($discountOptions),
                Rule::requiredIf(fn () => $this->promotionNeedsBillingPeriodDiscount('monthly_discount_percent')),
            ],
        ];
    }

    public function update(array $validated, bool $isSuperadmin): void
    {
        if (array_key_exists('plan_modules', $validated) && ! $isSuperadmin) {
            abort(403);
        }

        PlatformSetting::setValue('maintenance', [
            'enabled' => (bool) $validated['maintenance']['enabled'],
            'message' => $validated['maintenance']['message'] ?? '',
        ]);

        PlatformSetting::setValue('templates', [
            'email_default' => $validated['templates']['email_default'] ?? '',
            'quote_default' => $validated['templates']['quote_default'] ?? '',
            'invoice_default' => $validated['templates']['invoice_default'] ?? '',
        ]);

        PlatformSetting::setValue('public_navigation', $this->sanitizePublicNavigation(
            $validated['public_navigation'] ?? []
        ));

        PlatformSetting::setValue('plan_limits', $this->buildLimitPayload($validated['plan_limits'] ?? []));

        if ($isSuperadmin && array_key_exists('plan_modules', $validated)) {
            PlatformSetting::setValue('plan_modules', $this->buildModulePayload($validated['plan_modules'] ?? []));
        }

        PlatformSetting::setValue('plan_display', $this->buildDisplayPayload($validated['plan_display'] ?? []));

        app(BillingPlanService::class)->upsertPricing($validated['plan_prices'] ?? []);

        if (request()->exists('subscription_promotion')) {
            app(SubscriptionPromotionService::class)->updateFromAdminPayload(
                $validated['subscription_promotion'] ?? []
            );
        }
    }

    private function promotionNeedsBillingPeriodDiscount(string $otherField): bool
    {
        $promotionInput = request()->input('subscription_promotion', []);

        return (bool) ($promotionInput['enabled'] ?? false)
            && blank($promotionInput[$otherField] ?? null);
    }

    private function sanitizePublicNavigation(array $input): array
    {
        $contactFormUrl = trim((string) ($input['contact_form_url'] ?? ''));

        if ($contactFormUrl !== '' && ! str_starts_with($contactFormUrl, '/')) {
            $contactFormUrl = filter_var($contactFormUrl, FILTER_VALIDATE_URL) ? $contactFormUrl : '';
        }

        return [
            'contact_form_url' => $contactFormUrl,
        ];
    }

    private function buildLimitPayload(array $inputLimits): array
    {
        $payload = [];

        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputLimits[$planKey] ?? [];

            foreach ($this->limitKeys as $limitKey) {
                $value = $planInput[$limitKey] ?? null;
                $payload[$planKey][$limitKey] = is_numeric($value) ? max(0, (int) $value) : null;
            }
        }

        return $payload;
    }

    private function buildModulePayload(array $inputModules): array
    {
        $payload = [];

        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputModules[$planKey] ?? [];

            foreach ($this->moduleKeys as $moduleKey) {
                $value = $planInput[$moduleKey] ?? null;
                $payload[$planKey][$moduleKey] = $value === null ? true : (bool) $value;
            }
        }

        return $payload;
    }

    private function buildDisplayPayload(array $inputDisplay): array
    {
        $payload = [];

        foreach (config('billing.plans', []) as $planKey => $plan) {
            $planInput = $inputDisplay[$planKey] ?? [];
            $name = is_string($planInput['name'] ?? null) ? trim($planInput['name']) : '';
            $badge = is_string($planInput['badge'] ?? null) ? trim($planInput['badge']) : '';
            $price = $planInput['price'] ?? null;

            if (is_string($price)) {
                $price = trim($price);
                $price = $price === '' ? null : $price;
            }

            $features = $planInput['features'] ?? [];
            if (! is_array($features)) {
                $features = [];
            }

            $payload[$planKey] = [
                'name' => $name !== '' ? $name : ($plan['name'] ?? ucfirst($planKey)),
                'price' => $price,
                'badge' => $badge !== '' ? $badge : null,
                'features' => collect($features)
                    ->map(fn ($feature) => is_string($feature) ? trim($feature) : '')
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        return $payload;
    }
}
