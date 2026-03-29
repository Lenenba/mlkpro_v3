<?php

namespace App\Services\Demo;

use App\Models\AvailabilityException;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LoyaltyProgram;
use App\Models\MailingList;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationSetting;
use App\Models\ReservationWaitlist;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\VipTier;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoWorkspaceProvisioner
{
    public function __construct(private DemoWorkspaceCatalog $catalog) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $admin): DemoWorkspace
    {
        $payload['selected_modules'] = $this->normalizeModules($payload['selected_modules'] ?? []);
        $expiresAt = Carbon::parse((string) $payload['expires_at'])->endOfDay();

        return DB::transaction(function () use ($payload, $admin, $expiresAt) {
            $credentials = $this->generateCredentials((string) $payload['company_name']);
            $owner = $this->createOwner($payload, $credentials, $expiresAt);

            $workspace = DemoWorkspace::create([
                'owner_user_id' => $owner->id,
                'created_by_user_id' => $admin->id,
                'prospect_name' => (string) $payload['prospect_name'],
                'prospect_email' => $payload['prospect_email'] ?: null,
                'prospect_company' => $payload['prospect_company'] ?: null,
                'company_name' => (string) $payload['company_name'],
                'company_type' => (string) $payload['company_type'],
                'company_sector' => $payload['company_sector'] ?: null,
                'seed_profile' => (string) $payload['seed_profile'],
                'team_size' => (int) $payload['team_size'],
                'locale' => (string) $payload['locale'],
                'timezone' => (string) $payload['timezone'],
                'desired_outcome' => $payload['desired_outcome'] ?: null,
                'internal_notes' => $payload['internal_notes'] ?: null,
                'selected_modules' => $payload['selected_modules'],
                'configuration' => [
                    'profile_counts' => $this->catalog->seedCounts((string) $payload['seed_profile']),
                    'module_labels' => collect($payload['selected_modules'])
                        ->mapWithKeys(fn (string $key) => [$key => $this->catalog->moduleLabel($key)])
                        ->all(),
                ],
                'access_email' => $credentials['email'],
                'access_password' => $credentials['password'],
                'expires_at' => $expiresAt,
                'provisioned_at' => now(),
                'last_seeded_at' => now(),
            ]);

            $summary = $this->seedEnvironment($owner, $workspace);

            $workspace->forceFill([
                'seed_summary' => $summary,
            ])->save();

            return $workspace->fresh(['owner', 'creator']);
        });
    }

    public function updateExpiration(DemoWorkspace $workspace, Carbon $expiresAt): DemoWorkspace
    {
        $workspace->forceFill([
            'expires_at' => $expiresAt->copy()->endOfDay(),
        ])->save();

        if ($workspace->owner) {
            $workspace->owner->forceFill([
                'trial_ends_at' => $workspace->expires_at,
            ])->save();
        }

        return $workspace->fresh(['owner', 'creator']);
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array<int, string>
     */
    private function normalizeModules(array $selectedModules): array
    {
        $valid = array_fill_keys($this->catalog->moduleKeys(), true);

        return collect($selectedModules)
            ->filter(fn ($value) => is_string($value) && isset($valid[$value]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $credentials
     */
    private function createOwner(array $payload, array $credentials, Carbon $expiresAt): User
    {
        $companyName = trim((string) $payload['company_name']);
        $prospectName = trim((string) $payload['prospect_name']);
        $description = trim((string) ($payload['desired_outcome'] ?? ''));
        $timezone = (string) $payload['timezone'];

        return User::create([
            'name' => $prospectName !== '' ? $prospectName : $companyName.' Owner',
            'email' => $credentials['email'],
            'password' => Hash::make($credentials['password']),
            'role_id' => $this->resolveRoleId('owner', 'Account owner role'),
            'locale' => (string) $payload['locale'],
            'currency_code' => $this->currencyForTimezone($timezone),
            'company_name' => $companyName,
            'company_slug' => $this->uniqueCompanySlug($companyName),
            'company_description' => $description !== '' ? $description : 'Custom demo workspace prepared for a prospect walkthrough.',
            'company_country' => $this->countryForTimezone($timezone),
            'company_city' => $this->cityForSector((string) ($payload['company_sector'] ?? '')),
            'company_timezone' => $timezone,
            'company_type' => (string) $payload['company_type'],
            'company_sector' => $payload['company_sector'] ?: null,
            'company_team_size' => (int) $payload['team_size'],
            'onboarding_completed_at' => now(),
            'email_verified_at' => now(),
            'trial_ends_at' => $expiresAt,
            'is_demo' => true,
            'demo_type' => 'custom',
            'is_demo_user' => true,
            'demo_role' => 'custom_demo_owner',
            'company_features' => $this->catalog->featureMap($payload['selected_modules']),
            'company_limits' => $this->buildLimits((string) $payload['seed_profile']),
            'assistant_credit_balance' => in_array('assistant', $payload['selected_modules'], true) ? 250 : 0,
        ]);
    }

    private function uniqueCompanySlug(string $companyName): string
    {
        $base = Str::slug($companyName) ?: 'demo-company';
        $slug = $base.'-demo';
        $suffix = 1;

        while (User::query()->where('company_slug', $slug)->exists()) {
            $slug = $base.'-demo-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, int>
     */
    private function seedEnvironment(User $owner, DemoWorkspace $workspace): array
    {
        $selectedModules = $workspace->selected_modules ?? [];
        $counts = $this->catalog->seedCounts($workspace->seed_profile);

        $teamMembers = $this->createTeamMembers(
            $owner,
            $selectedModules,
            max(1, (int) $workspace->team_size),
            max(1, (int) ($counts['team'] ?? 1)),
            (string) $workspace->company_sector
        );
        $catalog = $this->createCatalog($owner, $selectedModules, (int) ($counts['catalog'] ?? 0), (string) $workspace->company_sector);
        $customers = $this->createCustomers($owner, (int) ($counts['customers'] ?? 0), (string) $workspace->company_sector);

        $loyalty = $this->createLoyaltySetup($owner, $selectedModules, $customers);
        $requests = $this->createRequests($owner, $selectedModules, $customers, $teamMembers, (int) ($counts['quotes'] ?? 0), $catalog['services']);
        $quotes = $this->createQuotes($owner, $selectedModules, $customers, $requests, $catalog, (int) ($counts['quotes'] ?? 0));
        $works = $this->createWorks($owner, $selectedModules, $customers, $quotes, $catalog, $teamMembers, (int) ($counts['works'] ?? 0));
        $tasks = $this->createTasks($owner, $selectedModules, $customers, $works, $teamMembers, (int) ($counts['tasks'] ?? 0));
        $invoices = $this->createInvoices($owner, $selectedModules, $customers, $works, $teamMembers);
        $reservationSummary = $this->createReservationFlow(
            $owner,
            $selectedModules,
            $customers,
            $catalog['services'],
            $teamMembers,
            (int) ($counts['reservations'] ?? 0),
            (int) ($counts['queue'] ?? 0),
            (string) $workspace->company_sector
        );
        $sales = $this->createSales($owner, $selectedModules, $customers, $catalog['products'], (int) ($counts['sales'] ?? 0));
        $marketing = $this->createMarketing($owner, $selectedModules, $customers);

        return [
            'customers' => $customers->count(),
            'team_members' => $teamMembers->count(),
            'services' => $catalog['services']->count(),
            'products' => $catalog['products']->count(),
            'requests' => $requests->count(),
            'quotes' => $quotes->count(),
            'works' => $works->count(),
            'tasks' => $tasks->count(),
            'invoices' => $invoices->count(),
            'reservations' => $reservationSummary['reservations'],
            'queue_items' => $reservationSummary['queue_items'],
            'waitlist_entries' => $reservationSummary['waitlist_entries'],
            'sales' => $sales->count(),
            'campaigns' => $marketing['campaigns'],
            'mailing_lists' => $marketing['mailing_lists'],
            'loyalty_program_enabled' => $loyalty ? 1 : 0,
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function createTeamMembers(
        User $owner,
        array $selectedModules,
        int $requestedTeamSize,
        int $profileTeamSize,
        string $sector
    ): Collection {
        $needsTeam = collect(['team_members', 'jobs', 'tasks', 'reservations', 'planning'])
            ->intersect($selectedModules)
            ->isNotEmpty();

        if (! $needsTeam) {
            return collect();
        }

        $targetCount = max(1, $requestedTeamSize, min($profileTeamSize, 6));
        $profiles = $this->teamProfilesForSector($sector);

        return collect(range(1, $targetCount))->map(function (int $index) use ($owner, $profiles) {
            $profile = $profiles[($index - 1) % count($profiles)];
            $emailDomain = config('demo.accounts_email_domain', 'example.test');

            $employee = User::create([
                'name' => (string) $profile['name'],
                'email' => Str::slug($profile['name']).'-'.$owner->id.'-'.$index.'@'.$emailDomain,
                'password' => Hash::make('password'),
                'role_id' => $this->resolveRoleId('employee', 'Employee role'),
                'locale' => $owner->locale,
                'currency_code' => $owner->businessCurrencyCode(),
                'company_name' => $owner->company_name,
                'company_type' => $owner->company_type,
                'company_sector' => $owner->company_sector,
                'company_timezone' => $owner->company_timezone,
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
                'is_demo' => true,
                'demo_type' => 'custom',
                'is_demo_user' => true,
                'demo_role' => 'custom_demo_staff',
            ]);

            return TeamMember::create([
                'account_id' => $owner->id,
                'user_id' => $employee->id,
                'role' => (string) $profile['role'],
                'title' => (string) $profile['title'],
                'phone' => $this->phoneForIndex($index),
                'permissions' => $this->permissionsForTeamRole((string) $profile['role']),
                'planning_rules' => [
                    'break_minutes' => 30,
                    'min_hours_day' => 4,
                    'max_hours_day' => 8,
                    'max_hours_week' => 40,
                ],
                'is_active' => true,
            ]);
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @return array{services: Collection<int, Product>, products: Collection<int, Product>}
     */
    private function createCatalog(User $owner, array $selectedModules, int $catalogCount, string $sector): array
    {
        $services = collect();
        $products = collect();
        $total = max(4, $catalogCount);

        if (in_array('services', $selectedModules, true)) {
            $serviceCategory = ProductCategory::create([
                'name' => 'Signature services',
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]);

            $services = collect($this->serviceCatalogForSector($sector))
                ->take(max(4, (int) ceil($total / 2)))
                ->values()
                ->map(function (array $item) use ($owner, $serviceCategory) {
                    return Product::create([
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'category_id' => $serviceCategory->id,
                        'stock' => 0,
                        'minimum_stock' => 0,
                        'price' => $item['price'],
                        'currency_code' => $owner->businessCurrencyCode(),
                        'unit' => 'service',
                        'cost_price' => round($item['price'] * 0.35, 2),
                        'margin_percent' => 65,
                        'tax_rate' => 15,
                        'is_active' => true,
                        'user_id' => $owner->id,
                        'item_type' => Product::ITEM_TYPE_SERVICE,
                        'tracking_type' => 'none',
                    ]);
                });
        }

        if (in_array('products', $selectedModules, true)) {
            $productCategory = ProductCategory::create([
                'name' => 'Featured products',
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]);

            $products = collect($this->productCatalogForSector($sector))
                ->take(max(4, $total))
                ->values()
                ->map(function (array $item, int $index) use ($owner, $productCategory) {
                    return Product::create([
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'category_id' => $productCategory->id,
                        'stock' => 20 + ($index * 3),
                        'minimum_stock' => 5,
                        'price' => $item['price'],
                        'currency_code' => $owner->businessCurrencyCode(),
                        'sku' => 'DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'unit' => 'item',
                        'supplier_name' => 'Demo Supplier Co.',
                        'cost_price' => round($item['price'] * 0.52, 2),
                        'margin_percent' => 48,
                        'tax_rate' => 15,
                        'is_active' => true,
                        'user_id' => $owner->id,
                        'item_type' => Product::ITEM_TYPE_PRODUCT,
                        'tracking_type' => 'stock',
                    ]);
                });
        }

        return [
            'services' => $services,
            'products' => $products,
        ];
    }

    private function createCustomers(User $owner, int $count, string $sector): Collection
    {
        $profiles = $this->customerProfilesForSector($sector);
        $target = max(6, $count);

        return collect(range(1, $target))->map(function (int $index) use ($owner, $profiles) {
            $profile = $profiles[($index - 1) % count($profiles)];

            return Customer::create([
                'user_id' => $owner->id,
                'first_name' => (string) $profile['first_name'],
                'last_name' => (string) $profile['last_name'],
                'company_name' => $profile['company_name'],
                'email' => strtolower($profile['first_name'].'.'.$profile['last_name']).'+'.$owner->id.$index.'@example.test',
                'phone' => $this->phoneForIndex($index + 20),
                'description' => (string) $profile['description'],
                'tags' => $profile['tags'],
                'refer_by' => 'Website form',
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
                'discount_rate' => $index % 5 === 0 ? 10 : 0,
                'is_active' => true,
                'portal_access' => false,
                'loyalty_points_balance' => 0,
            ]);
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     */
    private function createLoyaltySetup(User $owner, array $selectedModules, Collection $customers): ?LoyaltyProgram
    {
        if (! in_array('loyalty', $selectedModules, true)) {
            return null;
        }

        $vipTier = VipTier::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'code' => 'VIP-GOLD',
            'name' => 'Gold',
            'perks' => [
                'Priority booking',
                'Early access to launches',
                'Preferred service slots',
            ],
            'is_active' => true,
        ]);

        $customers->take(min(3, $customers->count()))->each(function (Customer $customer) use ($vipTier) {
            $customer->forceFill([
                'is_vip' => true,
                'vip_tier_id' => $vipTier->id,
                'vip_tier_code' => $vipTier->code,
                'vip_since_at' => now()->subMonths(4),
                'loyalty_points_balance' => 1200,
            ])->save();
        });

        return LoyaltyProgram::create([
            'user_id' => $owner->id,
            'is_enabled' => true,
            'points_per_currency_unit' => 1,
            'minimum_spend' => 25,
            'rounding_mode' => LoyaltyProgram::ROUND_FLOOR,
            'points_label' => 'Points',
        ]);
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, TeamMember>  $teamMembers
     * @param  Collection<int, Product>  $services
     * @return Collection<int, LeadRequest>
     */
    private function createRequests(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $teamMembers,
        int $count,
        Collection $services
    ): Collection {
        if (! in_array('requests', $selectedModules, true)) {
            return collect();
        }

        return collect(range(1, max(2, $count)))
            ->map(function (int $index) use ($owner, $customers, $teamMembers, $services) {
                $customer = $customers[$index % $customers->count()];
                $member = $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()] : null;
                $service = $services->isNotEmpty() ? $services[$index % $services->count()] : null;
                $statuses = [
                    LeadRequest::STATUS_NEW,
                    LeadRequest::STATUS_CONTACTED,
                    LeadRequest::STATUS_QUALIFIED,
                    LeadRequest::STATUS_QUOTE_SENT,
                ];

                return LeadRequest::create([
                    'user_id' => $owner->id,
                    'customer_id' => $customer->id,
                    'assigned_team_member_id' => $member?->id,
                    'channel' => $index % 2 === 0 ? 'website' : 'phone',
                    'status' => $statuses[$index % count($statuses)],
                    'service_type' => $service?->name,
                    'urgency' => $index % 3 === 0 ? 'high' : 'normal',
                    'title' => 'Need help with '.strtolower($service?->name ?: 'service delivery'),
                    'description' => 'Prospect would like a demo-ready request flow with qualification already started.',
                    'contact_name' => trim($customer->first_name.' '.$customer->last_name),
                    'contact_email' => $customer->email,
                    'contact_phone' => $customer->phone,
                    'country' => $owner->company_country,
                    'city' => $owner->company_city,
                    'street1' => '123 Demo Street',
                    'postal_code' => 'H2X 1Y4',
                    'is_serviceable' => true,
                    'status_updated_at' => now()->subDays(3 - min($index, 3)),
                    'next_follow_up_at' => now()->addDays($index),
                ]);
            })
            ->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, LeadRequest>  $requests
     * @param  array{services: Collection<int, Product>, products: Collection<int, Product>}  $catalog
     * @return Collection<int, Quote>
     */
    private function createQuotes(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $requests,
        array $catalog,
        int $count
    ): Collection {
        if (! in_array('quotes', $selectedModules, true)) {
            return collect();
        }

        $lines = $catalog['services']->isNotEmpty() ? $catalog['services'] : $catalog['products'];
        if ($lines->isEmpty()) {
            return collect();
        }

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $requests, $lines) {
            $customer = $customers[$index % $customers->count()];
            $request = $requests->isNotEmpty() ? $requests[$index % $requests->count()] : null;
            $picked = $lines->take(min(2, $lines->count()));
            $subtotal = (float) $picked->sum('price');
            $statuses = ['draft', 'sent', 'accepted', 'accepted'];

            $quote = Quote::create([
                'user_id' => $owner->id,
                'job_title' => 'Custom package for '.$customer->company_name,
                'status' => $statuses[$index % count($statuses)],
                'customer_id' => $customer->id,
                'request_id' => $request?->id,
                'total' => $subtotal,
                'subtotal' => $subtotal,
                'currency_code' => $owner->businessCurrencyCode(),
                'is_fixed' => true,
                'notes' => 'Prepared for a prospect demo with ready-to-review commercial scope.',
                'messages' => 'Pricing includes onboarding and a first delivery wave.',
                'accepted_at' => $index % 3 === 0 ? now()->subDays(2) : null,
            ]);

            $pivotData = [];
            foreach ($picked as $product) {
                $pivotData[$product->id] = [
                    'quantity' => 1,
                    'price' => (float) $product->price,
                    'description' => $product->description,
                    'total' => (float) $product->price,
                ];
            }
            $quote->syncProductLines($pivotData);
            $quote->refresh();
            $quote->syncRequestStatusFromQuote();

            return $quote;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Quote>  $quotes
     * @param  array{services: Collection<int, Product>, products: Collection<int, Product>}  $catalog
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Work>
     */
    private function createWorks(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $quotes,
        array $catalog,
        Collection $teamMembers,
        int $count
    ): Collection {
        if (! in_array('jobs', $selectedModules, true)) {
            return collect();
        }

        $lines = $catalog['services']->isNotEmpty() ? $catalog['services'] : $catalog['products'];

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $quotes, $lines, $teamMembers) {
            $customer = $customers[$index % $customers->count()];
            $quote = $quotes->isNotEmpty() ? $quotes[$index % $quotes->count()] : null;
            $startDate = now()->subDays(max(0, 4 - $index));
            $statuses = [
                Work::STATUS_SCHEDULED,
                Work::STATUS_IN_PROGRESS,
                Work::STATUS_COMPLETED,
                Work::STATUS_PENDING_REVIEW,
            ];
            $attachedLines = $lines->take(min(2, $lines->count()));
            $subtotal = (float) $attachedLines->sum('price');

            $work = Work::create([
                'user_id' => $owner->id,
                'customer_id' => $customer->id,
                'quote_id' => $quote?->id,
                'job_title' => ($quote?->job_title ?: 'Service delivery').' - phase '.$index,
                'instructions' => 'Demo-ready operational record with assigned team and billable scope.',
                'start_date' => $startDate->toDateString(),
                'end_date' => $startDate->copy()->addDay()->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '11:30:00',
                'is_all_day' => false,
                'later' => false,
                'status' => $statuses[$index % count($statuses)],
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'billing_mode' => 'per_visit',
                'billing_cycle' => 'on_completion',
                'billing_grouping' => 'per_work',
            ]);

            $work->products()->attach(
                $attachedLines->mapWithKeys(fn (Product $product) => [
                    $product->id => [
                        'quantity' => 1,
                        'price' => (float) $product->price,
                        'description' => $product->description,
                        'total' => (float) $product->price,
                    ],
                ])->all()
            );

            if ($teamMembers->isNotEmpty()) {
                $selectedMembers = $teamMembers->take(min(2, $teamMembers->count()));
                $work->teamMembers()->attach(
                    $selectedMembers->mapWithKeys(fn (TeamMember $member, int $memberIndex) => [
                        $member->id => ['role' => $memberIndex === 0 ? 'lead' : 'support'],
                    ])->all()
                );
            }

            return $work;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Work>  $works
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Task>
     */
    private function createTasks(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $works,
        Collection $teamMembers,
        int $count
    ): Collection {
        if (! in_array('tasks', $selectedModules, true) || $works->isEmpty()) {
            return collect();
        }

        $statuses = ['todo', 'in_progress', 'done'];

        return collect(range(1, max(3, $count)))->map(function (int $index) use ($owner, $customers, $works, $teamMembers, $statuses) {
            $work = $works[$index % $works->count()];
            $customer = $customers[$index % $customers->count()];
            $member = $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()] : null;
            $status = $statuses[$index % count($statuses)];

            return Task::create([
                'account_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'assigned_team_member_id' => $member?->id,
                'customer_id' => $customer->id,
                'work_id' => $work->id,
                'title' => $index % 2 === 0 ? 'Confirm materials and arrival window' : 'Prepare completion checklist',
                'description' => 'Task seeded for the demo to show operational coordination and ownership.',
                'status' => $status,
                'billable' => $index % 3 === 0,
                'due_date' => now()->addDays($index)->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'completed_at' => $status === 'done' ? now()->subDay() : null,
            ]);
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Work>  $works
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return Collection<int, Invoice>
     */
    private function createInvoices(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $works,
        Collection $teamMembers
    ): Collection {
        if (! in_array('invoices', $selectedModules, true) || $works->isEmpty()) {
            return collect();
        }

        return $works->take(min(3, $works->count()))->values()->map(function (Work $work, int $index) use ($owner, $customers, $teamMembers) {
            $customer = $customers[$index % $customers->count()];
            $totals = [180, 325, 490];
            $statuses = ['sent', 'partial', 'paid'];
            $total = (float) ($totals[$index % count($totals)] ?? 240);

            $invoice = Invoice::create([
                'work_id' => $work->id,
                'customer_id' => $customer->id,
                'user_id' => $owner->id,
                'status' => $statuses[$index % count($statuses)],
                'total' => $total,
                'currency_code' => $owner->businessCurrencyCode(),
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'work_id' => $work->id,
                'assigned_team_member_id' => $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()]->id : null,
                'title' => $work->job_title,
                'description' => 'Main invoice line created for the demo workspace.',
                'scheduled_date' => $work->start_date,
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'assignee_name' => $teamMembers->isNotEmpty() ? $teamMembers[$index % $teamMembers->count()]->user?->name : null,
                'task_status' => 'completed',
                'quantity' => 1,
                'unit_price' => $total,
                'currency_code' => $owner->businessCurrencyCode(),
                'total' => $total,
            ]);

            if ($index > 0) {
                Payment::create([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $customer->id,
                    'user_id' => $owner->id,
                    'amount' => $index === 1 ? $total / 2 : $total,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'method' => 'card',
                    'provider' => 'demo',
                    'status' => 'paid',
                    'reference' => 'DEMO-PAY-'.Str::upper(Str::random(6)),
                    'paid_at' => now()->subDay(),
                ]);

                $invoice->refreshPaymentStatus();
            }

            return $invoice->fresh();
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Product>  $services
     * @param  Collection<int, TeamMember>  $teamMembers
     * @return array{reservations:int, queue_items:int, waitlist_entries:int}
     */
    private function createReservationFlow(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $services,
        Collection $teamMembers,
        int $reservationCount,
        int $queueCount,
        string $sector
    ): array {
        if (! in_array('reservations', $selectedModules, true) || $services->isEmpty() || $teamMembers->isEmpty()) {
            return [
                'reservations' => 0,
                'queue_items' => 0,
                'waitlist_entries' => 0,
            ];
        }

        ReservationSetting::create([
            'account_id' => $owner->id,
            'team_member_id' => null,
            'business_preset' => in_array($sector, ['salon', 'wellness'], true) ? 'salon' : 'service_general',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 5,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'team_member',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
            'deposit_required' => false,
            'deposit_amount' => 0,
            'no_show_fee_enabled' => false,
            'no_show_fee_amount' => 0,
        ]);

        $teamMembers->each(function (TeamMember $member, int $index) use ($owner) {
            ReservationSetting::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'business_preset' => 'salon',
                'buffer_minutes' => 10,
                'slot_interval_minutes' => 30,
                'min_notice_minutes' => 0,
                'max_advance_days' => 60,
                'cancellation_cutoff_hours' => 12,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
                'late_release_minutes' => 5,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'team_member',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => false,
                'deposit_required' => false,
                'deposit_amount' => 0,
                'no_show_fee_enabled' => false,
                'no_show_fee_amount' => 0,
            ]);

            ReservationResource::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'name' => 'Chair '.($index + 1),
                'type' => 'chair',
                'capacity' => 1,
                'is_active' => true,
                'metadata' => ['kind' => 'barber_chair'],
            ]);

            foreach (range(1, 5) as $dayOffset) {
                WeeklyAvailability::create([
                    'account_id' => $owner->id,
                    'team_member_id' => $member->id,
                    'day_of_week' => $dayOffset,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'is_active' => true,
                ]);
            }

            TeamMemberShift::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'created_by_user_id' => $owner->id,
                'approved_by_user_id' => $owner->id,
                'approved_at' => now()->subDays(2),
                'kind' => 'shift',
                'status' => 'approved',
                'title' => 'Frontline shift',
                'shift_date' => now()->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 30,
            ]);
        });

        AvailabilityException::create([
            'account_id' => $owner->id,
            'team_member_id' => $teamMembers->first()?->id,
            'date' => now()->addDays(4)->toDateString(),
            'start_time' => '13:00:00',
            'end_time' => '17:00:00',
            'type' => AvailabilityException::TYPE_CLOSED,
            'reason' => 'Training block',
        ]);

        $reservations = collect(range(1, max(4, $reservationCount)))->map(function (int $index) use ($owner, $customers, $services, $teamMembers) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[$index % $customers->count()];
            $service = $services[$index % $services->count()];
            $startsAt = now()->copy()->startOfDay()->addHours(9 + $index);
            $statuses = [
                Reservation::STATUS_CONFIRMED,
                Reservation::STATUS_CONFIRMED,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_PENDING,
            ];

            return Reservation::create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'status' => $statuses[$index % count($statuses)],
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => $owner->company_timezone ?: 'UTC',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(60),
                'duration_minutes' => 60,
                'buffer_minutes' => 10,
                'internal_notes' => 'Demo reservation generated for queue and booking walkthrough.',
                'client_notes' => $index % 2 === 0 ? 'Customer prefers the senior stylist.' : null,
                'created_by_user_id' => $owner->id,
            ]);
        })->values();

        $queueItems = collect(range(1, max(2, $queueCount)))->map(function (int $index) use ($owner, $customers, $services, $teamMembers, $reservations) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[$index % $customers->count()];
            $service = $services[$index % $services->count()];
            $reservation = $reservations[$index % $reservations->count()];
            $checkedInAt = now()->subMinutes($index * 7);
            $statuses = [
                ReservationQueueItem::STATUS_CHECKED_IN,
                ReservationQueueItem::STATUS_CALLED,
                ReservationQueueItem::STATUS_IN_SERVICE,
                ReservationQueueItem::STATUS_PRE_CALLED,
            ];
            $status = $statuses[$index % count($statuses)];

            return ReservationQueueItem::create([
                'account_id' => $owner->id,
                'reservation_id' => $reservation->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'team_member_id' => $member->id,
                'created_by_user_id' => $owner->id,
                'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                'source' => 'staff',
                'queue_number' => 'SAL-'.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT),
                'status' => $status,
                'priority' => $status === ReservationQueueItem::STATUS_IN_SERVICE ? 2 : 0,
                'estimated_duration_minutes' => 45,
                'checked_in_at' => $checkedInAt,
                'pre_called_at' => in_array($status, [ReservationQueueItem::STATUS_PRE_CALLED, ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_IN_SERVICE], true) ? $checkedInAt->copy()->addMinutes(5) : null,
                'called_at' => in_array($status, [ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_IN_SERVICE], true) ? $checkedInAt->copy()->addMinutes(10) : null,
                'started_at' => $status === ReservationQueueItem::STATUS_IN_SERVICE ? $checkedInAt->copy()->addMinutes(13) : null,
                'position' => $index,
                'eta_minutes' => max(5, $index * 12),
                'metadata' => ['label' => $customer->company_name ?: trim($customer->first_name.' '.$customer->last_name)],
            ]);
        })->values();

        $waitlists = collect(range(1, 2))->map(function (int $index) use ($owner, $customers, $services, $teamMembers) {
            $member = $teamMembers[$index % $teamMembers->count()];
            $customer = $customers[($index + 2) % $customers->count()];
            $service = $services[$index % $services->count()];
            $start = now()->addDays(2)->setTime(14 + $index, 0);

            return ReservationWaitlist::create([
                'account_id' => $owner->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'team_member_id' => $member->id,
                'status' => ReservationWaitlist::STATUS_PENDING,
                'requested_start_at' => $start,
                'requested_end_at' => $start->copy()->addHour(),
                'duration_minutes' => 60,
                'party_size' => 1,
                'notes' => 'Prospect waitlist example for the live demo.',
                'metadata' => ['channel' => 'website'],
            ]);
        })->values();

        return [
            'reservations' => $reservations->count(),
            'queue_items' => $queueItems->count(),
            'waitlist_entries' => $waitlists->count(),
        ];
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Sale>
     */
    private function createSales(
        User $owner,
        array $selectedModules,
        Collection $customers,
        Collection $products,
        int $count
    ): Collection {
        if (! in_array('sales', $selectedModules, true) || $products->isEmpty()) {
            return collect();
        }

        return collect(range(1, max(2, $count)))->map(function (int $index) use ($owner, $customers, $products) {
            $customer = $customers[$index % $customers->count()];
            $picked = $products->take(min(2, $products->count()));
            $subtotal = (float) $picked->sum('price');
            $sale = Sale::create([
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
                'customer_id' => $customer->id,
                'status' => $index % 2 === 0 ? Sale::STATUS_PAID : Sale::STATUS_PENDING,
                'payment_provider' => 'demo',
                'subtotal' => $subtotal,
                'tax_total' => round($subtotal * 0.15, 2),
                'currency_code' => $owner->businessCurrencyCode(),
                'discount_rate' => $index % 3 === 0 ? 10 : 0,
                'discount_total' => $index % 3 === 0 ? round($subtotal * 0.1, 2) : 0,
                'loyalty_points_redeemed' => 0,
                'loyalty_discount_total' => 0,
                'total' => round($subtotal * 1.15, 2),
                'delivery_fee' => 0,
                'fulfillment_method' => $index % 2 === 0 ? 'pickup' : 'delivery',
                'fulfillment_status' => $index % 2 === 0 ? Sale::FULFILLMENT_READY_FOR_PICKUP : Sale::FULFILLMENT_PENDING,
                'scheduled_for' => now()->addDays($index),
                'source' => 'pos',
                'paid_at' => $index % 2 === 0 ? now()->subHours(6) : null,
            ]);

            foreach ($picked as $product) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'description' => $product->description,
                    'quantity' => 1,
                    'price' => $product->price,
                    'currency_code' => $owner->businessCurrencyCode(),
                    'total' => $product->price,
                ]);
            }

            return $sale;
        })->values();
    }

    /**
     * @param  array<int, string>  $selectedModules
     * @param  Collection<int, Customer>  $customers
     * @return array{campaigns:int, mailing_lists:int}
     */
    private function createMarketing(User $owner, array $selectedModules, Collection $customers): array
    {
        if (! in_array('campaigns', $selectedModules, true)) {
            return [
                'campaigns' => 0,
                'mailing_lists' => 0,
            ];
        }

        $mailingList = MailingList::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'name' => 'VIP repeat customers',
            'description' => 'Mailing list prepared for a tailored lifecycle campaign demo.',
            'tags' => ['vip', 'repeat', 'demo'],
        ]);

        $mailingList->customers()->attach(
            $customers->take(min(5, $customers->count()))->mapWithKeys(fn (Customer $customer) => [
                $customer->id => [
                    'added_by_user_id' => $owner->id,
                    'added_at' => now()->subDays(3),
                ],
            ])->all()
        );

        Campaign::create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
            'name' => 'Spring retention push',
            'campaign_type' => Campaign::TYPE_PROMOTION,
            'campaign_direction' => Campaign::DIRECTION_CUSTOMER_MARKETING,
            'prospecting_enabled' => false,
            'offer_mode' => $owner->company_type === 'products' ? Campaign::OFFER_MODE_PRODUCTS : Campaign::OFFER_MODE_SERVICES,
            'language_mode' => $owner->locale === 'fr' ? Campaign::LANGUAGE_MODE_FR : Campaign::LANGUAGE_MODE_EN,
            'type' => Campaign::TYPE_PROMOTION,
            'status' => Campaign::STATUS_DRAFT,
            'schedule_type' => Campaign::SCHEDULE_SCHEDULED,
            'scheduled_at' => now()->addDays(5),
            'locale' => $owner->locale,
            'cta_url' => '/pricing',
            'is_marketing' => true,
            'last_run_at' => null,
            'settings' => [
                'mailing_lists' => [$mailingList->id],
                'objective' => 'Retention',
            ],
        ]);

        return [
            'campaigns' => 1,
            'mailing_lists' => 1,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function generateCredentials(string $companyName): array
    {
        $domain = config('demo.accounts_email_domain', 'example.test');
        $base = Str::slug($companyName) ?: 'demo-workspace';
        $email = $base.'-'.Str::lower(Str::random(6)).'@'.$domain;

        while (User::query()->where('email', $email)->exists()) {
            $email = $base.'-'.Str::lower(Str::random(6)).'@'.$domain;
        }

        return [
            'email' => $email,
            'password' => 'Demo!'.Str::upper(Str::random(6)),
        ];
    }

    private function resolveRoleId(string $name, string $description): int
    {
        return Role::query()->firstOrCreate(
            ['name' => $name],
            ['description' => $description]
        )->id;
    }

    /**
     * @return array<string, int>
     */
    private function buildLimits(string $seedProfile): array
    {
        $counts = $this->catalog->seedCounts($seedProfile);

        return [
            'quotes' => max(50, ($counts['quotes'] ?? 4) * 12),
            'requests' => max(50, ($counts['quotes'] ?? 4) * 10),
            'jobs' => max(50, ($counts['works'] ?? 4) * 12),
            'tasks' => max(80, ($counts['tasks'] ?? 8) * 10),
            'invoices' => 80,
            'products' => max(50, ($counts['catalog'] ?? 10) * 8),
            'services' => max(30, ($counts['catalog'] ?? 10) * 4),
            'team_members' => max(5, ($counts['team'] ?? 3) * 2),
            'sales' => max(30, ($counts['sales'] ?? 4) * 10),
            'plan_scans' => 25,
        ];
    }

    private function currencyForTimezone(string $timezone): string
    {
        return match ($timezone) {
            'Europe/Paris' => 'EUR',
            'Europe/London' => 'GBP',
            default => 'CAD',
        };
    }

    private function countryForTimezone(string $timezone): string
    {
        return match ($timezone) {
            'Europe/Paris' => 'France',
            'Europe/London' => 'United Kingdom',
            'America/New_York' => 'United States',
            default => 'Canada',
        };
    }

    private function cityForSector(string $sector): string
    {
        return match ($sector) {
            'salon', 'wellness' => 'Montreal',
            'restaurant' => 'Paris',
            'retail' => 'Toronto',
            'field_services' => 'Laval',
            default => 'Montreal',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamProfilesForSector(string $sector): array
    {
        return match ($sector) {
            'salon', 'wellness' => [
                ['name' => 'Maya Brooks', 'title' => 'Senior Stylist', 'role' => 'admin'],
                ['name' => 'Noah Turner', 'title' => 'Barber', 'role' => 'member'],
                ['name' => 'Lina Carter', 'title' => 'Front Desk Lead', 'role' => 'sales_manager'],
                ['name' => 'Jules Rivers', 'title' => 'Color Specialist', 'role' => 'member'],
            ],
            'retail' => [
                ['name' => 'Emma Cole', 'title' => 'Store Manager', 'role' => 'admin'],
                ['name' => 'Lucas Hart', 'title' => 'Sales Lead', 'role' => 'sales_manager'],
                ['name' => 'Nina Vale', 'title' => 'Floor Specialist', 'role' => 'member'],
            ],
            default => [
                ['name' => 'Alex Carter', 'title' => 'Operations Lead', 'role' => 'admin'],
                ['name' => 'Sam Rivera', 'title' => 'Field Specialist', 'role' => 'member'],
                ['name' => 'Taylor Reed', 'title' => 'Account Coordinator', 'role' => 'sales_manager'],
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForTeamRole(string $role): array
    {
        return match ($role) {
            'admin' => ['jobs.view', 'jobs.edit', 'tasks.view', 'tasks.edit', 'sales.manage', 'reservations.manage'],
            'sales_manager' => ['sales.manage', 'quotes.view', 'quotes.edit', 'reservations.view'],
            default => ['jobs.view', 'tasks.view', 'tasks.edit', 'reservations.view'],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serviceCatalogForSector(string $sector): array
    {
        return match ($sector) {
            'salon', 'wellness' => [
                ['name' => 'Signature cut', 'description' => 'Haircut with consultation and finish.', 'price' => 55],
                ['name' => 'Beard sculpt', 'description' => 'Precision beard shaping and treatment.', 'price' => 35],
                ['name' => 'Keratin care', 'description' => 'Smoothing treatment for damaged hair.', 'price' => 120],
                ['name' => 'Color refresh', 'description' => 'Tone and gloss package.', 'price' => 95],
                ['name' => 'Express spa ritual', 'description' => 'Quick relaxation and treatment session.', 'price' => 80],
            ],
            'restaurant' => [
                ['name' => 'Lunch tasting', 'description' => 'Menu tasting slot for partners.', 'price' => 40],
                ['name' => 'Private table booking', 'description' => 'Reserved premium seating experience.', 'price' => 65],
                ['name' => 'Chef consultation', 'description' => 'Custom event planning session.', 'price' => 150],
                ['name' => 'Catering assessment', 'description' => 'On-site catering planning meeting.', 'price' => 90],
            ],
            default => [
                ['name' => 'Site assessment', 'description' => 'On-site discovery and scoping visit.', 'price' => 120],
                ['name' => 'Installation package', 'description' => 'Delivery, setup, and QA handoff.', 'price' => 340],
                ['name' => 'Monthly maintenance', 'description' => 'Recurring service visit with reporting.', 'price' => 180],
                ['name' => 'Emergency intervention', 'description' => 'Priority same-day dispatch slot.', 'price' => 260],
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productCatalogForSector(string $sector): array
    {
        return match ($sector) {
            'salon', 'wellness' => [
                ['name' => 'Hydration shampoo', 'description' => 'Retail shampoo for dry hair.', 'price' => 28],
                ['name' => 'Beard oil', 'description' => 'Finishing oil with cedar notes.', 'price' => 22],
                ['name' => 'Keratin mask', 'description' => 'Weekly restorative treatment.', 'price' => 34],
                ['name' => 'Matte styling clay', 'description' => 'Flexible hold styling clay.', 'price' => 26],
                ['name' => 'Scalp serum', 'description' => 'Cooling leave-in scalp treatment.', 'price' => 31],
            ],
            default => [
                ['name' => 'Starter kit', 'description' => 'High-margin entry bundle for new customers.', 'price' => 79],
                ['name' => 'Premium bundle', 'description' => 'Most requested package with accessories.', 'price' => 149],
                ['name' => 'Refill pack', 'description' => 'Repeat purchase pack for loyal customers.', 'price' => 39],
                ['name' => 'Pro accessory', 'description' => 'Upsell item for advanced users.', 'price' => 54],
                ['name' => 'Gift set', 'description' => 'Seasonal gifting package.', 'price' => 95],
            ],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerProfilesForSector(string $sector): array
    {
        return match ($sector) {
            'salon', 'wellness' => [
                ['first_name' => 'Sarah', 'last_name' => 'Parker', 'company_name' => 'Studio North', 'description' => 'High-value repeat client.', 'tags' => ['vip', 'color']],
                ['first_name' => 'Kevin', 'last_name' => 'Moore', 'company_name' => 'Atelier KM', 'description' => 'Needs fast recurring appointments.', 'tags' => ['beard', 'monthly']],
                ['first_name' => 'Amelie', 'last_name' => 'Roy', 'company_name' => 'Roy Creative', 'description' => 'Books premium treatment packages.', 'tags' => ['premium']],
                ['first_name' => 'David', 'last_name' => 'Lopez', 'company_name' => 'Lopez Legal', 'description' => 'Walk-in converted to regular.', 'tags' => ['walk-in']],
            ],
            'retail' => [
                ['first_name' => 'Sophie', 'last_name' => 'Nguyen', 'company_name' => 'North Market', 'description' => 'Strong average order value.', 'tags' => ['retail', 'repeat']],
                ['first_name' => 'Marcus', 'last_name' => 'Bell', 'company_name' => 'Bell & Co', 'description' => 'Responds well to promotions.', 'tags' => ['promo']],
                ['first_name' => 'Elena', 'last_name' => 'Martin', 'company_name' => 'Maison Martin', 'description' => 'High-potential loyalty prospect.', 'tags' => ['vip']],
                ['first_name' => 'Jordan', 'last_name' => 'Lee', 'company_name' => 'JL Studio', 'description' => 'Frequent pickup customer.', 'tags' => ['pickup']],
            ],
            default => [
                ['first_name' => 'Olivia', 'last_name' => 'Green', 'company_name' => 'Green Properties', 'description' => 'Multi-site account with ongoing needs.', 'tags' => ['account', 'multi-site']],
                ['first_name' => 'Michael', 'last_name' => 'Stone', 'company_name' => 'Stone Logistics', 'description' => 'Needs rapid response and reporting.', 'tags' => ['priority']],
                ['first_name' => 'Chloe', 'last_name' => 'Benoit', 'company_name' => 'Benoit Design', 'description' => 'Values polished quoting flow.', 'tags' => ['quote']],
                ['first_name' => 'Ethan', 'last_name' => 'Cole', 'company_name' => 'Cole Ventures', 'description' => 'Good upsell and maintenance potential.', 'tags' => ['upsell']],
            ],
        };
    }

    private function phoneForIndex(int $index): string
    {
        return '+1 514 555 '.str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT);
    }
}
