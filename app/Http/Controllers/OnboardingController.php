<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\ProductCategory;
use App\Models\PlatformSetting;
use App\Notifications\WelcomeEmailNotification;
use App\Services\BillingSubscriptionService;
use App\Services\CompanyFeatureService;
use App\Services\PlatformAdminNotifier;
use App\Services\StripeBillingService;
use App\Support\PlanDisplay;
use App\Support\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

class OnboardingController extends Controller
{
    private const SECTOR_CATEGORIES = [
        'salon' => ['Coupe', 'Coloration', 'Coiffage', 'Soin capillaire', 'Barbier'],
        'restaurant' => ['Service en salle', 'Menu degustation', 'Evenement prive', 'Takeout', 'Livraison'],
        'service_general' => ['Installation', 'Entretien', 'Reparation', 'Conseil', 'Autres'],
        'menuiserie' => ['Fabrication', 'Installation', 'Reparation', 'Finition', 'Sur mesure'],
        'plomberie' => ['Installation', 'Reparation', 'Debouchage', 'Entretien', 'Urgence'],
        'electricite' => ['Installation', 'Maintenance', 'Mise aux normes', 'Depannage', 'Domotique'],
        'peinture' => ['Interieur', 'Exterieur', 'Preparation', 'Finition', 'Retouches'],
        'toiture' => ['Inspection', 'Reparation', 'Entretien', 'Nettoyage', 'Isolation'],
        'renovation' => ['Demolition', 'Gros oeuvre', 'Finitions', 'Amenagement', 'Suivi chantier'],
        'paysagisme' => ['Entretien', 'Plantation', 'Tonte', 'Arrosage', 'Amenagement'],
        'climatisation' => ['Installation', 'Maintenance', 'Reparation', 'Nettoyage', 'Mise en service'],
        'nettoyage' => ['Residentiel', 'Commercial', 'Post-chantier', 'Desinfection', 'Vitres'],
        'autre' => ['Installation', 'Entretien', 'Reparation', 'Conseil', 'Autres'],
    ];

    private const TEAM_PERMISSION_FEATURE_MAP = [
        'jobs.view' => 'jobs',
        'jobs.edit' => 'jobs',
        'tasks.view' => 'tasks',
        'tasks.create' => 'tasks',
        'tasks.edit' => 'tasks',
        'tasks.delete' => 'tasks',
        'quotes.view' => 'quotes',
        'quotes.create' => 'quotes',
        'quotes.edit' => 'quotes',
        'quotes.send' => 'quotes',
        'reservations.view' => 'reservations',
        'reservations.queue' => 'reservations',
        'reservations.manage' => 'reservations',
        'sales.manage' => 'sales',
        'sales.pos' => 'sales',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->inertiaOrJson('Onboarding/Index', [
                'preset' => (object) [],
                'plans' => $this->planOptions(),
                'planLimits' => $this->planLimits(),
            ]);
        }

        if (!$user->isAccountOwner()) {
            return $this->inertiaOrJson('Onboarding/PendingOwner', []);
        }

        $companyLogo = null;
        if ($user->company_logo) {
            $path = $user->company_logo;
            $companyLogo = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : Storage::disk('public')->url($path);
        }

        return $this->inertiaOrJson('Onboarding/Index', [
            'preset' => [
                'company_name' => $user->company_name,
                'company_logo' => $companyLogo,
                'company_description' => $user->company_description,
                'company_country' => $user->company_country,
                'company_province' => $user->company_province,
                'company_city' => $user->company_city,
                'company_type' => $user->company_type,
                'company_sector' => $user->company_sector,
                'company_team_size' => $user->company_team_size,
                'two_factor_method' => $user->two_factor_method,
                'onboarding_completed_at' => $user->onboarding_completed_at,
            ],
            'plans' => $this->planOptions(),
            'planLimits' => $this->planLimits(),
        ]);
    }

    public function store(Request $request)
    {
        $creator = $request->user();
        if (!$creator) {
            abort(401);
        }

        if (!$creator->isAccountOwner()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Only the account owner can complete onboarding.',
                ], 403);
            }

            return redirect()->route('dashboard')->with('error', 'Only the account owner can complete onboarding.');
        }

        $wasOnboarded = (bool) $creator->onboarding_completed_at;
        $planRule = $wasOnboarded
            ? ['nullable', Rule::in($this->planKeysForOnboarding())]
            : ['required', Rule::in($this->planKeysForOnboarding())];
        $termsRule = $wasOnboarded ? ['nullable'] : ['accepted'];
        $twoFactorRule = $wasOnboarded
            ? ['nullable', Rule::in(['email', 'app'])]
            : ($this->shouldReturnJson($request)
                ? ['nullable', Rule::in(['email', 'app'])]
                : ['required', Rule::in(['email', 'app'])]);

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|max:2048',
            'company_description' => 'nullable|string|max:2000',
            'company_country' => 'nullable|string|max:255',
            'company_province' => 'nullable|string|max:255',
            'company_city' => 'nullable|string|max:255',
            'company_type' => 'required|string|in:services,products',
            'company_sector' => 'required|string|max:255',
            'company_team_size' => 'nullable|integer|min:1|max:5000',

            'invites' => 'nullable|array|max:20',
            'invites.*.name' => 'required|string|max:255',
            'invites.*.email' => 'required|string|lowercase|email|max:255|distinct|unique:users,email',
            'invites.*.role' => 'required|string|in:admin,member',

            'plan_key' => $planRule,
            'accept_terms' => $termsRule,
            'two_factor_method' => $twoFactorRule,
        ]);

        $ownerRoleId = Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        )->id;

        $accountOwner = $creator;
        if ($creator->role_id !== $ownerRoleId) {
            $creator->update(['role_id' => $ownerRoleId]);
        }

        $companyLogoPath = $accountOwner->company_logo;
        if ($request->hasFile('company_logo')) {
            $companyLogoPath = $request->file('company_logo')->store('company/logos', 'public');
        }

        $accountOwner->update([
            'company_name' => $validated['company_name'],
            'company_logo' => $companyLogoPath,
            'company_description' => $validated['company_description'] ?? null,
            'company_country' => $validated['company_country'] ?? null,
            'company_province' => $validated['company_province'] ?? null,
            'company_city' => $validated['company_city'] ?? null,
            'company_type' => $validated['company_type'],
            'company_sector' => $validated['company_sector'],
            'company_team_size' => $validated['company_team_size'] ?? null,
        ]);

        $twoFactorMethod = $validated['two_factor_method'] ?? null;
        if (!$twoFactorMethod && !$wasOnboarded) {
            $twoFactorMethod = 'email';
        }
        if ($twoFactorMethod) {
            $updates = [
                'two_factor_method' => $twoFactorMethod,
                'two_factor_code' => null,
                'two_factor_expires_at' => null,
            ];

            if ($twoFactorMethod === 'email') {
                $updates['two_factor_secret'] = null;
            }

            $accountOwner->forceFill($updates)->save();
        }

        if ($validated['company_type'] === 'services') {
            $this->seedSectorCategories($accountOwner, $creator, $validated['company_sector'] ?? null);
        }

        if ($validated['company_type'] === 'products') {
            $features = (array) ($accountOwner->company_features ?? []);
            if (!array_key_exists('sales', $features)) {
                $features['sales'] = true;
            }
            $accountOwner->update([
                'company_features' => $features,
            ]);
        }

        $invites = $validated['invites'] ?? [];
        if (!is_array($invites)) {
            $invites = [];
        }

        if ($wasOnboarded) {
            $request->session()->forget('onboarding_invites');
            $messageParts = ['Onboarding completed.'];
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => implode(' ', $messageParts),
                    'user' => $accountOwner->fresh(),
                ]);
            }

            return redirect()->route('dashboard')->with('success', implode(' ', $messageParts));
        }

        $request->session()->put('onboarding_invites', $invites);

        return $this->startCheckout($request, $accountOwner, (string) $validated['plan_key']);
    }

    public function billing(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if (!$user->isAccountOwner()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Only the account owner can complete onboarding.',
                ], 403);
            }

            return redirect()->route('dashboard')->with('error', 'Only the account owner can complete onboarding.');
        }

        $status = (string) $request->query('status');
        if ($status !== 'success') {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Checkout canceled.',
                ], 409);
            }

            return redirect()->route('onboarding.index')->with('error', 'Checkout canceled.');
        }

        $billingService = app(BillingSubscriptionService::class);
        if ($billingService->isStripe()) {
            $sessionId = (string) $request->query('session_id');
            if ($sessionId === '') {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Checkout session is missing.',
                    ], 422);
                }

                return redirect()->route('onboarding.index')->with('error', 'Checkout session is missing.');
            }

            try {
                app(StripeBillingService::class)->syncFromCheckoutSession($sessionId, $user);
            } catch (\Throwable $exception) {
                report($exception);
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Unable to sync subscription.',
                    ], 422);
                }

                return redirect()->route('onboarding.index')->with('error', 'Unable to sync subscription.');
            }
        } else {
            try {
                $this->syncLatestSubscription($user);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $message = $this->completeOnboarding($request, $user);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'user' => $user->fresh(),
            ]);
        }

        return redirect()->route('dashboard')->with('success', $message);
    }

    private function seedSectorCategories(User $accountOwner, User $creator, ?string $sector): void
    {
        $normalized = Str::of((string) $sector)->lower()->trim()->toString();
        $categories = self::SECTOR_CATEGORIES[$normalized] ?? null;

        if (!$categories) {
            $remoteCategories = $this->discoverSectorCategories((string) $sector);
            $base = self::SECTOR_CATEGORIES['autre'];
            $label = preg_replace('/\s+/', ' ', trim((string) $sector));
            $categories = array_merge($remoteCategories, $label !== '' ? [$label] : [], $base);
            $categories = array_values(array_unique($categories));
        }

        foreach ($categories as $name) {
            $clean = preg_replace('/\s+/', ' ', trim((string) $name));
            if ($clean === '') {
                continue;
            }

            $category = ProductCategory::resolveForAccount($accountOwner->id, $creator->id, $clean);
            if ($category && $category->user_id === $accountOwner->id && $category->archived_at) {
                $category->update(['archived_at' => null]);
            }
        }
    }

    private function discoverSectorCategories(string $sector): array
    {
        $query = preg_replace('/\s+/', ' ', trim($sector));
        if ($query === '') {
            return [];
        }

        $titles = $this->fetchWikipediaTitles($query);
        if (!$titles) {
            $titles = $this->fetchWikipediaTitles($query . ' services');
        }

        $categories = [];
        foreach ($titles as $title) {
            $clean = preg_replace('/\s+/', ' ', trim((string) $title));
            $clean = preg_replace('/\s+\(.*\)$/', '', $clean);
            if ($clean !== '') {
                $categories[] = $clean;
            }
        }

        $categories = array_values(array_unique($categories));
        return array_slice($categories, 0, 3);
    }

    private function fetchWikipediaTitles(string $query): array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get('https://fr.wikipedia.org/w/api.php', [
                'action' => 'opensearch',
                'search' => $query,
                'limit' => 5,
                'namespace' => 0,
                'format' => 'json',
            ]);

            if (!$response->ok()) {
                return [];
            }

            $data = $response->json();
            $titles = is_array($data) && isset($data[1]) && is_array($data[1]) ? $data[1] : [];

            return array_values(array_filter($titles, fn($title) => trim((string) $title) !== ''));
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function startCheckout(Request $request, User $accountOwner, string $planKey)
    {
        $billingService = app(BillingSubscriptionService::class);
        if (!$billingService->providerReady()) {
            throw ValidationException::withMessages([
                'plan_key' => ['Billing is not configured yet.'],
            ]);
        }

        if (!$billingService->isStripe()) {
            throw ValidationException::withMessages([
                'plan_key' => ['Onboarding checkout is only available with Stripe.'],
            ]);
        }

        $plans = config('billing.plans', []);
        $plan = $plans[$planKey] ?? null;
        $priceId = $plan['price_id'] ?? null;
        if (!$priceId) {
            throw ValidationException::withMessages([
                'plan_key' => ['The selected plan is not available for checkout.'],
            ]);
        }

        $trialEndsAt = now()->addMonthNoOverflow();
        $seatQuantity = $billingService->resolveSeatQuantity($accountOwner);
        $successUrl = route('onboarding.billing', ['status' => 'success']);
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('onboarding.billing', ['status' => 'cancel']);

        $session = app(StripeBillingService::class)->createCheckoutSession(
            $accountOwner,
            $priceId,
            $successUrl,
            $cancelUrl,
            $planKey,
            $seatQuantity,
            $trialEndsAt
        );

        $url = $session['url'] ?? null;
        if (!$url) {
            throw ValidationException::withMessages([
                'plan_key' => ['Unable to start checkout.'],
            ]);
        }

        return $this->checkoutResponse($request, $url);
    }

    private function checkoutResponse(Request $request, string $url)
    {
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'checkout_url' => $url,
            ]);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->away($url);
    }

    private function completeOnboarding(Request $request, User $accountOwner): string
    {
        $invitePayload = $this->applyInvitesFromSession($request, $accountOwner);
        $invitePasswords = $invitePayload['passwords'];
        $inviteCount = $invitePayload['count'];

        $wasOnboarded = (bool) $accountOwner->onboarding_completed_at;
        if (!$wasOnboarded) {
            $accountOwner->forceFill(['onboarding_completed_at' => now()])->save();
        }

        if (!$wasOnboarded && $accountOwner->email) {
            NotificationDispatcher::send($accountOwner, new WelcomeEmailNotification($accountOwner), [
                'user_id' => $accountOwner->id,
            ]);
        }

        if (!$wasOnboarded) {
            try {
                $notifier = app(PlatformAdminNotifier::class);

                $notifier->notify('onboarding_completed', 'Onboarding completed', [
                    'intro' => ($accountOwner->company_name ?: $accountOwner->email) . ' finished onboarding.',
                    'details' => [
                        ['label' => 'Company', 'value' => $accountOwner->company_name ?: 'Not set'],
                        ['label' => 'Owner', 'value' => $accountOwner->email ?: 'Unknown'],
                        ['label' => 'Type', 'value' => $accountOwner->company_type ?: 'Not set'],
                        ['label' => 'Sector', 'value' => $accountOwner->company_sector ?: 'Not set'],
                        ['label' => 'Team invites', 'value' => (string) $inviteCount],
                    ],
                    'actionUrl' => route('superadmin.tenants.show', $accountOwner->id),
                    'actionLabel' => 'View tenant',
                    'reference' => 'onboarding:' . $accountOwner->id,
                    'severity' => 'success',
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $messageParts = ['Onboarding completed.'];
        if ($invitePasswords) {
            $messageParts[] = 'Team passwords: ' . implode(', ', $invitePasswords);
        }

        return implode(' ', $messageParts);
    }

    private function applyInvitesFromSession(Request $request, User $accountOwner): array
    {
        $invites = $request->session()->pull('onboarding_invites', []);
        if (!is_array($invites) || $invites === []) {
            return [
                'passwords' => [],
                'count' => 0,
            ];
        }

        $allowedPermissions = $this->allowedTeamPermissionIdsForAccount($accountOwner);

        $employeeRoleId = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        )->id;

        $invitePasswords = [];
        $inviteCount = 0;

        foreach ($invites as $invite) {
            $name = trim((string) ($invite['name'] ?? ''));
            $email = strtolower(trim((string) ($invite['email'] ?? '')));
            $role = (string) ($invite['role'] ?? 'member');

            if ($name === '' || $email === '') {
                continue;
            }

            if (User::query()->where('email', $email)->exists()) {
                continue;
            }

            if (!in_array($role, ['admin', 'member'], true)) {
                $role = 'member';
            }

            $plainPassword = Str::random(14);
            $memberUser = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($plainPassword),
                'role_id' => $employeeRoleId,
                'email_verified_at' => now(),
            ]);

            TeamMember::create([
                'account_id' => $accountOwner->id,
                'user_id' => $memberUser->id,
                'role' => $role,
                'permissions' => $this->defaultPermissionsForRole($role, $allowedPermissions),
                'is_active' => true,
            ]);

            $inviteCount += 1;
            $invitePasswords[] = $email . '=' . $plainPassword;
        }

        return [
            'passwords' => $invitePasswords,
            'count' => $inviteCount,
        ];
    }

    private function planKeysForOnboarding(): array
    {
        $plans = config('billing.plans', []);
        $preferred = ['starter', 'growth', 'scale'];
        return array_values(array_filter($preferred, fn (string $key) => array_key_exists($key, $plans)));
    }

    private function defaultPermissionsForRole(string $role, ?array $allowedPermissions = null): array
    {
        $defaults = match ($role) {
            'admin' => [
                'jobs.view',
                'jobs.edit',
                'tasks.view',
                'tasks.create',
                'tasks.edit',
                'tasks.delete',
                'quotes.view',
                'quotes.create',
                'quotes.edit',
                'quotes.send',
                'reservations.view',
                'reservations.queue',
                'reservations.manage',
                'sales.manage',
            ],
            'member' => [
                'jobs.view',
                'tasks.view',
                'tasks.edit',
                'reservations.view',
                'reservations.queue',
            ],
            default => [
                'jobs.view',
            ],
        };

        if (!is_array($allowedPermissions)) {
            return $defaults;
        }

        return array_values(array_intersect($defaults, $allowedPermissions));
    }

    private function allowedTeamPermissionIdsForAccount(User $accountOwner): array
    {
        $featureService = app(CompanyFeatureService::class);
        $permissionIds = array_keys(self::TEAM_PERMISSION_FEATURE_MAP);

        return array_values(array_filter($permissionIds, function (string $permissionId) use ($accountOwner, $featureService): bool {
            $feature = self::TEAM_PERMISSION_FEATURE_MAP[$permissionId] ?? null;
            if (!$feature) {
                return true;
            }

            return $featureService->hasFeature($accountOwner, $feature);
        }));
    }

    private function planOptions(): array
    {
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        $plans = config('billing.plans', []);

        return collect($this->planKeysForOnboarding())
            ->map(function (string $key) use ($plans, $planDisplayOverrides) {
                $plan = $plans[$key] ?? [];
                $display = PlanDisplay::merge($plan, $key, $planDisplayOverrides);

                return [
                    'key' => $key,
                    'name' => $display['name'],
                    'price_id' => $plan['price_id'] ?? null,
                    'price' => $display['price'],
                    'display_price' => $this->resolvePlanDisplayPrice($display['price']),
                    'features' => $display['features'],
                    'badge' => $display['badge'],
                ];
            })
            ->values()
            ->all();
    }

    private function resolvePlanDisplayPrice($raw): ?string
    {
        $rawValue = is_string($raw) ? trim($raw) : $raw;

        if (is_numeric($rawValue)) {
            return Cashier::formatAmount((int) round((float) $rawValue * 100), config('cashier.currency', 'USD'));
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }

    private function planLimits(): array
    {
        return PlatformSetting::getValue('plan_limits', []);
    }

    private function syncLatestSubscription(User $user): void
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
