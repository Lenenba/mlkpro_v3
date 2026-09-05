<?php

namespace App\Services\Demo\Generators;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\ServiceMaterial;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\TaskMaterial;
use App\Models\TaskStatusHistory;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\TeamMemberShift;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Models\Work;
use App\Models\WorkChecklistItem;
use App\Models\WorkMedia;
use App\Models\WorkRating;
use App\Notifications\DemoActionNotification;
use App\Services\Accounting\AccountingSyncService;
use App\Services\Demo\DemoScenarioContext;
use App\Services\FinanceApprovalService;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Blueprint-driven data generator for service companies that execute work on
 * customer sites. It deliberately models requests, work orders and quality
 * evidence instead of appointment queues.
 */
final class DemoFieldOperationsGenerator
{
    private const TAX_RATE = 0.14975;

    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly AccountingSyncService $accountingSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, int>  $targets
     * @return array<string, mixed>
     */
    public function generate(DemoScenarioContext $context, array $blueprint, array $targets): array
    {
        $this->configureOwner($context, $blueprint);
        $team = $this->createTeam($context, $blueprint);
        $catalog = $this->createCatalog($context, $blueprint);
        $inventoryMovements = $this->seedInventoryHistory(
            $context,
            $blueprint,
            $catalog['products'],
            (int) $targets['inventory_movements'],
        );
        $customers = $this->createCustomersAndSites(
            $context,
            $blueprint,
            (int) $targets['customers'],
            (int) $targets['properties'],
        );
        $pipeline = $this->createPipeline(
            $context,
            $blueprint,
            $customers,
            $team['team_members'],
            $catalog['services'],
            (int) ($targets['requests'] ?? $targets['prospects']),
            (int) $targets['quotes'],
        );
        $works = $this->createWorks(
            $context,
            $blueprint,
            $customers,
            $pipeline['quotes'],
            $team['team_members'],
            $catalog['services'],
            (int) $targets['works'],
        );
        $execution = $this->createExecutionHistory(
            $context,
            $works,
            $team['team_members'],
            $catalog,
            (int) $targets['tasks'],
            (int) $targets['work_checklist_items'],
            (int) $targets['work_media'],
            (int) ($targets['task_materials'] ?? 0),
            (int) ($targets['task_status_histories'] ?? 0),
            (int) ($targets['reviews'] ?? $targets['work_ratings']),
        );
        $billing = $this->createBillingHistory(
            $context,
            $customers,
            $works,
            $pipeline['quotes'],
            $team['team_members'],
            (int) $targets['invoices'],
            (int) $targets['payments'],
        );
        $expenses = $this->createExpenseHistory(
            $context,
            $blueprint,
            $customers['customers'],
            $works,
            $team['team_members'],
            (int) $targets['expenses'],
        );
        $notifications = $this->createActionNotifications($context, $blueprint);
        $accounting = $this->accountingSyncService->syncAccount((int) $context->owner->id);

        return [
            'team_members' => $team['team_members'],
            'staff_users' => $team['staff_users'],
            'services' => $catalog['services'],
            'products' => $catalog['products'],
            'customers' => $customers['customers'],
            'customers_by_story' => $customers['customers_by_story'],
            'properties' => $customers['properties'],
            'requests' => $pipeline['requests'],
            'service_requests' => $pipeline['service_requests'],
            'quotes' => $pipeline['quotes'],
            'works' => $works,
            'tasks' => $execution['tasks'],
            'work_checklist_items' => $execution['work_checklist_items'],
            'work_media' => $execution['work_media'],
            'task_materials' => $execution['task_materials'],
            'task_status_histories' => $execution['task_status_histories'],
            'reviews' => $execution['reviews'],
            'invoices' => $billing['invoices'],
            'payments' => $billing['payments'],
            'transactions' => $billing['transactions'],
            'expenses' => $expenses,
            'inventory_movements' => $inventoryMovements,
            'notifications' => $notifications,
            'accounting' => $accounting,
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     */
    private function configureOwner(DemoScenarioContext $context, array $blueprint): void
    {
        $identity = (array) ($blueprint['identity'] ?? []);
        $address = (array) ($identity['address'] ?? []);
        $owner = $context->owner;
        $features = (array) ($owner->company_features ?? []);

        foreach ((array) config('demo_scenarios.scenarios.'.($blueprint['key'] ?? '').'.required_modules', []) as $module) {
            $key = trim((string) $module);
            if ($key !== '') {
                $features[$key] = true;
            }
        }

        $owner->forceFill([
            'name' => (string) ($identity['owner_name'] ?? $owner->name),
            'profile_picture' => (string) ($identity['owner_avatar'] ?? '/images/presets/avatar-1.svg'),
            'locale' => (string) ($identity['locale'] ?? 'fr'),
            'currency_code' => (string) ($identity['currency_code'] ?? 'CAD'),
            'phone_number' => $identity['phone'] ?? null,
            'company_name' => (string) ($identity['name'] ?? $owner->company_name),
            'company_description' => (string) ($identity['description'] ?? ''),
            'company_country' => (string) ($address['country_code'] ?? 'CA'),
            'company_province' => (string) ($address['province'] ?? 'QC'),
            'company_city' => (string) ($address['city'] ?? 'Longueuil'),
            'company_timezone' => $context->timezone,
            'company_type' => 'services',
            'company_sector' => (string) ($identity['sector'] ?? 'nettoyage'),
            'company_team_size' => count((array) ($blueprint['employees'] ?? [])),
            'company_features' => $features,
            'company_limits' => array_replace((array) ($owner->company_limits ?? []), [
                'customers' => 10000,
                'team_members' => 30,
                'products' => 1500,
                'invoices_monthly' => 10000,
                'storage_mb' => 5000,
            ]),
            'is_demo' => true,
            'is_demo_user' => true,
            'demo_type' => 'scenario:'.(string) $blueprint['key'],
            'demo_role' => 'scenario_owner',
        ])->save();

        $this->backdate('users', (int) $owner->id, $context->referenceDate->subMonths(13)->startOfMonth());
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{team_members: Collection<string, TeamMember>, staff_users: Collection<string, User>}
     */
    private function createTeam(DemoScenarioContext $context, array $blueprint): array
    {
        $owner = $context->owner;
        $employeeRole = Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role'],
        );
        $members = collect();
        $users = collect();

        foreach (array_values((array) ($blueprint['employees'] ?? [])) as $index => $profile) {
            $key = (string) $profile['key'];
            $user = $index === 0
                ? $owner
                : User::query()->create([
                    'name' => (string) $profile['name'],
                    'profile_picture' => '/images/presets/avatar-'.(($index % 4) + 1).'.svg',
                    'email' => sprintf(
                        '%s-%d@boreal-proprete.example',
                        Str::slug((string) $profile['name']),
                        abs((int) $context->workspace->id),
                    ),
                    'password' => Hash::make('password'),
                    'role_id' => $employeeRole->id,
                    'locale' => 'fr',
                    'currency_code' => 'CAD',
                    'company_name' => $owner->company_name,
                    'company_type' => 'services',
                    'company_sector' => 'nettoyage',
                    'company_timezone' => $context->timezone,
                    'email_verified_at' => $context->referenceDate->utc(),
                    'onboarding_completed_at' => $context->referenceDate->utc(),
                    'is_demo' => true,
                    'demo_type' => 'scenario:'.(string) $blueprint['key'],
                    'is_demo_user' => true,
                    'demo_role' => 'scenario_staff',
                ]);

            $member = TeamMember::query()->create([
                'account_id' => $owner->id,
                'user_id' => $user->id,
                'role' => (string) ($profile['role_key'] ?? ($index === 0 ? 'admin' : 'member')),
                'title' => (string) $profile['title'],
                'phone' => sprintf('+1 450 555 %04d', 3100 + $index),
                'permissions' => array_values((array) ($profile['permissions'] ?? [])),
                'planning_rules' => [
                    'scenario_key' => $key,
                    'demo_access_role' => $profile['demo_access_role'] ?? null,
                    'specialties' => array_values((array) ($profile['specialties'] ?? [])),
                    'territories' => array_values((array) ($profile['territories'] ?? ['Grand Montréal'])),
                    'vehicle' => $profile['vehicle'] ?? null,
                    'break_minutes' => 30,
                    'min_hours_day' => 4,
                    'max_hours_day' => 10,
                    'max_hours_week' => 42,
                ],
                'is_active' => true,
            ]);
            $foundationDate = $context->referenceDate->subMonths(12)->startOfMonth()->addDays($index * 8);
            $this->backdate('users', (int) $user->id, $foundationDate);
            $this->backdate('team_members', (int) $member->id, $foundationDate);

            $schedule = (array) ($profile['schedule'] ?? $this->defaultSchedule($index));
            foreach ($schedule as $day => $hours) {
                WeeklyAvailability::query()->create([
                    'account_id' => $owner->id,
                    'team_member_id' => $member->id,
                    'day_of_week' => (int) $day,
                    'start_time' => (string) $hours['starts_at'].':00',
                    'end_time' => (string) $hours['ends_at'].':00',
                    'is_active' => true,
                ]);
            }

            $this->createPlanningHistory($context, $member, $user, $schedule, $index);
            $members->put($key, $member);
            $users->put($key, $user);
        }

        return ['team_members' => $members, 'staff_users' => $users];
    }

    /**
     * @return array<int, array{starts_at: string, ends_at: string}>
     */
    private function defaultSchedule(int $index): array
    {
        $commercial = $index > 0 && $index % 3 === 0;

        return collect(range(1, 5))->mapWithKeys(fn (int $day): array => [
            $day => $commercial
                ? ['starts_at' => '14:00', 'ends_at' => '22:00']
                : ['starts_at' => '07:30', 'ends_at' => '16:00'],
        ])->all();
    }

    /**
     * @param  array<int, array{starts_at: string, ends_at: string}>  $schedule
     */
    private function createPlanningHistory(
        DemoScenarioContext $context,
        TeamMember $member,
        User $user,
        array $schedule,
        int $memberIndex,
    ): void {
        $date = $context->referenceDate;
        $attendanceCreated = 0;

        while ($attendanceCreated < 18) {
            $date = $date->subDay();
            $hours = $schedule[$date->dayOfWeekIso] ?? null;
            if (! is_array($hours)) {
                continue;
            }

            $clockIn = $date->setTimeFromTimeString((string) $hours['starts_at'])
                ->addMinutes(($memberIndex * 3 + $attendanceCreated) % 11);
            $clockOut = $date->setTimeFromTimeString((string) $hours['ends_at'])
                ->subMinutes(($memberIndex + $attendanceCreated) % 13);
            TeamMemberAttendance::query()->create([
                'account_id' => $context->owner->id,
                'user_id' => $user->id,
                'team_member_id' => $member->id,
                'clock_in_at' => $clockIn->utc(),
                'clock_out_at' => $clockOut->utc(),
                'method' => 'mobile',
                'clock_out_method' => 'mobile',
                'current_status' => TeamMemberAttendance::STATUS_OFFLINE,
            ]);
            $attendanceCreated++;
        }

        $future = $context->referenceDate;
        $shiftCreated = 0;
        while ($shiftCreated < 12) {
            $future = $future->addDay();
            $hours = $schedule[$future->dayOfWeekIso] ?? null;
            if (! is_array($hours)) {
                continue;
            }

            TeamMemberShift::query()->create([
                'account_id' => $context->owner->id,
                'team_member_id' => $member->id,
                'created_by_user_id' => $context->owner->id,
                'approved_by_user_id' => $context->owner->id,
                'approved_at' => $context->referenceDate->subDays(3)->utc(),
                'kind' => 'shift',
                'status' => 'approved',
                'title' => $memberIndex % 3 === 0 ? 'Contrats commerciaux' : 'Interventions terrain',
                'notes' => $shiftCreated === 3 && $memberIndex === 6
                    ? 'Créneau de remplacement et approvisionnement.'
                    : null,
                'shift_date' => $future->toDateString(),
                'start_time' => (string) $hours['starts_at'].':00',
                'end_time' => (string) $hours['ends_at'].':00',
                'break_minutes' => 30,
                'recurrence_group_id' => 'boreal-'.$member->id,
            ]);
            $shiftCreated++;
        }

        TeamMemberAttendance::query()->create([
            'account_id' => $context->owner->id,
            'user_id' => $user->id,
            'team_member_id' => $member->id,
            'clock_in_at' => $context->referenceDate->setTime(8 + ($memberIndex % 2), $memberIndex * 3)->utc(),
            'clock_out_at' => null,
            'method' => 'mobile',
            'clock_out_method' => null,
            'current_status' => [
                TeamMemberAttendance::STATUS_AVAILABLE,
                TeamMemberAttendance::STATUS_BUSY,
                TeamMemberAttendance::STATUS_BREAK,
            ][$memberIndex % 3],
        ]);
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{services: Collection<string, Product>, products: Collection<string, Product>}
     */
    private function createCatalog(DemoScenarioContext $context, array $blueprint): array
    {
        $owner = $context->owner;
        $categories = collect();
        $categoryDefinitions = collect((array) ($blueprint['service_categories'] ?? []))
            ->concat(collect((array) ($blueprint['product_categories'] ?? [])))
            ->unique('key')
            ->values();

        foreach ($categoryDefinitions as $definition) {
            $categories->put((string) $definition['key'], ProductCategory::query()->create([
                'name' => (string) $definition['name'],
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]));
        }

        foreach (collect((array) ($blueprint['products'] ?? []))->pluck('category_key')->unique() as $categoryKey) {
            if ($categories->has((string) $categoryKey)) {
                continue;
            }

            $categories->put((string) $categoryKey, ProductCategory::query()->create([
                'name' => Str::headline((string) $categoryKey),
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]));
        }

        $services = collect();
        foreach ((array) ($blueprint['services'] ?? []) as $definition) {
            $key = (string) $definition['key'];
            $duration = (int) ($definition['duration_minutes'] ?? 120);
            $service = Product::query()->create([
                'name' => (string) $definition['name'],
                'description' => (string) $definition['description'],
                'tags' => [
                    'scenario:'.(string) $blueprint['key'],
                    'key:'.$key,
                    'duration:'.$duration,
                    ...array_values((array) ($definition['tags'] ?? [])),
                ],
                'category_id' => $categories->get((string) $definition['category_key'])?->id,
                'stock' => 0,
                'minimum_stock' => 0,
                'price' => (float) $definition['price'],
                'currency_code' => 'CAD',
                'image' => 'images/landing/stock/cleaning-team-office.jpg',
                'unit' => 'service',
                'cost_price' => round((float) $definition['price'] * 0.43, 2),
                'margin_percent' => 57,
                'tax_rate' => 14.975,
                'is_active' => (bool) ($definition['active'] ?? true),
                'user_id' => $owner->id,
                'item_type' => Product::ITEM_TYPE_SERVICE,
                'tracking_type' => 'none',
            ]);
            $this->backdate('products', (int) $service->id, $context->referenceDate->subMonths(12)->startOfMonth());
            $services->put($key, $service);
        }

        $products = collect();
        $suppliers = collect((array) ($blueprint['suppliers'] ?? []))->keyBy('key');
        foreach ((array) ($blueprint['products'] ?? []) as $definition) {
            $key = (string) $definition['key'];
            $cost = (float) ($definition['cost'] ?? 0);
            $price = (float) ($definition['price'] ?? 0);
            $supplier = (array) $suppliers->get((string) ($definition['supplier_key'] ?? ''), []);
            $product = Product::query()->create([
                'name' => (string) $definition['name'],
                'description' => (string) ($definition['description'] ?? 'Consommable professionnel pour interventions terrain.'),
                'tags' => [
                    'scenario:'.(string) $blueprint['key'],
                    'key:'.$key,
                    'professional',
                ],
                'category_id' => $categories->get((string) $definition['category_key'])?->id,
                'stock' => 0,
                'minimum_stock' => (int) ($definition['reorder_threshold'] ?? 0),
                'price' => $price,
                'currency_code' => 'CAD',
                'image' => 'images/placeholders/product-default.jpg',
                'sku' => 'BOR-'.Str::upper(Str::replace('_', '-', $key)),
                'unit' => (string) ($definition['unit'] ?? 'unit'),
                'supplier_name' => $definition['supplier_name'] ?? $supplier['name'] ?? 'Distribution Hygiène Québec',
                'supplier_email' => $definition['supplier_email'] ?? $supplier['email'] ?? 'commandes@distribution-hygiene.example',
                'cost_price' => $cost,
                'margin_percent' => $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0,
                'tax_rate' => 14.975,
                'is_active' => (bool) ($definition['active'] ?? true),
                'user_id' => $owner->id,
                'item_type' => Product::ITEM_TYPE_PRODUCT,
                'tracking_type' => (string) ($definition['tracking_type'] ?? 'stock'),
            ]);
            $this->backdate('products', (int) $product->id, $context->referenceDate->subMonths(12)->startOfMonth());
            $product->setAttribute('scenario_stock_target', (int) ($definition['stock_on_hand'] ?? 10));
            $products->put($key, $product);
        }

        foreach ((array) ($blueprint['services'] ?? []) as $serviceDefinition) {
            $service = $services->get((string) $serviceDefinition['key']);
            foreach ((array) ($serviceDefinition['materials'] ?? []) as $index => $material) {
                $product = $products->get((string) $material['product_key']);
                if (! $service || ! $product) {
                    continue;
                }

                ServiceMaterial::query()->create([
                    'service_id' => $service->id,
                    'product_id' => $product->id,
                    'label' => $product->name,
                    'description' => 'Consommable prévu dans la procédure de service.',
                    'unit' => $product->unit,
                    'quantity' => (float) ($material['quantity'] ?? 1),
                    'unit_price' => (float) $product->cost_price,
                    'billable' => false,
                    'sort_order' => $index,
                ]);
            }
        }

        return ['services' => $services, 'products' => $products];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<string, Product>  $products
     */
    private function seedInventoryHistory(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $products,
        int $target,
    ): int {
        if ($target < $products->count()) {
            throw new RuntimeException('Field operations inventory target must cover every catalog product.');
        }

        $historyStart = $context->referenceDate->subMonths(11)->startOfMonth();
        $created = 0;

        foreach ($products as $index => $product) {
            $movement = $this->inventoryService->adjust(
                $product,
                max(0, (int) $product->getAttribute('scenario_stock_target')),
                'in',
                [
                    'account_id' => $context->owner->id,
                    'actor_id' => $context->owner->id,
                    'reason' => 'scenario_opening_stock',
                    'unit_cost' => $product->cost_price,
                    'note' => 'Stock de départ Boréal Propreté.',
                    'meta' => ['scenario_key' => (string) $blueprint['key']],
                ],
            );
            $this->backdate('product_stock_movements', (int) $movement->id, $historyStart->addDays($index));
            $created++;
        }

        while ($created < $target) {
            $product = $products->values()[$created % $products->count()];
            $pairedOffset = $created - $products->count();
            $isLastUnpaired = $created === $target - 1 && $pairedOffset % 2 === 0;
            $type = $isLastUnpaired ? 'adjust' : ($pairedOffset % 2 === 0 ? 'in' : 'out');
            $quantity = $type === 'adjust' ? 0 : 1;
            $movement = $this->inventoryService->adjust($product, $quantity, $type, [
                'account_id' => $context->owner->id,
                'actor_id' => $context->owner->id,
                'reason' => match ($type) {
                    'in' => 'supplier_delivery',
                    'out' => 'field_consumption',
                    default => 'cycle_count',
                },
                'unit_cost' => $product->cost_price,
                'note' => match ($type) {
                    'in' => 'Réception fournisseur planifiée.',
                    'out' => 'Consommation affectée à une équipe terrain.',
                    default => 'Inventaire cyclique sans écart.',
                },
                'meta' => ['scenario_key' => (string) $blueprint['key']],
            ]);
            $date = $this->historicalDate($context, $created, $target);
            $this->backdate('product_stock_movements', (int) $movement->id, $date);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{
     *   customers: Collection<int, Customer>,
     *   customers_by_story: Collection<string, Customer>,
     *   properties: Collection<int, Property>,
     *   properties_by_customer: Collection<int, Collection<int, Property>>
     * }
     */
    private function createCustomersAndSites(
        DemoScenarioContext $context,
        array $blueprint,
        int $customerTarget,
        int $propertyTarget,
    ): array {
        $stories = array_values((array) ($blueprint['client_stories'] ?? $blueprint['customer_stories'] ?? []));
        if ($customerTarget < count($stories) || $propertyTarget < $customerTarget) {
            throw new RuntimeException('Field operations targets cannot fit the named customers and one site per customer.');
        }

        $customers = collect();
        $byStory = collect();
        $properties = collect();
        $propertiesByCustomer = collect();
        $historyStart = $context->referenceDate->subMonths(13)->startOfMonth();
        $firstNames = [
            'Laurence', 'Olivier', 'Sarah', 'Nicolas', 'Myriam', 'Thomas', 'Ariane', 'Karim',
            'Sophie', 'David', 'Isabelle', 'Marc', 'Nadia', 'Félix', 'Julie', 'Antoine',
        ];
        $lastNames = [
            'Bélanger', 'Tremblay', 'Bouchard', 'Roy', 'Pelletier', 'Mercier', 'Haddad', 'Gagnon',
            'Morin', 'Girard', 'Lefebvre', 'Dubois', 'Desjardins', 'Cloutier', 'Paquette', 'Caron',
        ];
        $genericCustomerTarget = $customerTarget - count($stories);
        if ($genericCustomerTarget > count($firstNames) * count($lastNames)) {
            throw new RuntimeException('Field operations customer name pool cannot provide unique demo identities.');
        }

        for ($index = 0; $index < $customerTarget; $index++) {
            $story = $stories[$index] ?? null;
            $storyKey = is_array($story) ? (string) $story['key'] : null;
            $profile = is_array($story) ? (array) ($story['profile'] ?? []) : [];
            $contactName = is_array($story)
                ? trim((string) ($profile['contact_name'] ?? $story['name'] ?? ''))
                : '';
            $contactParts = preg_split('/\s+/', $contactName, 2) ?: [];
            $genericIndex = max(0, $index - count($stories));
            $firstNameIndex = $genericIndex % count($firstNames);
            $lastNameIndex = ($firstNameIndex + intdiv($genericIndex, count($firstNames))) % count($lastNames);
            $firstName = is_array($story) ? (string) ($contactParts[0] ?? $story['name']) : $firstNames[$firstNameIndex];
            $lastName = is_array($story) ? (string) ($contactParts[1] ?? 'Client') : $lastNames[$lastNameIndex];
            $isCompanyStory = is_array($story) && (string) ($profile['client_type'] ?? '') === 'company';
            $companyName = is_array($story)
                ? ($isCompanyStory ? (string) $story['name'] : null)
                : ($index % 3 === 0 ? 'Entreprise '.$lastName.' '.($index + 1) : null);
            $email = is_array($story) && filled($story['email'] ?? null)
                ? (string) $story['email']
                : sprintf('client-%03d-%d@boreal-proprete.example', $index + 1, abs((int) $context->workspace->id));
            $customer = Customer::query()->create([
                'user_id' => $context->owner->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company_name' => $companyName,
                'email' => $email,
                'phone' => is_array($story) ? ($story['phone'] ?? sprintf('+1 514 555 %04d', 4000 + $index)) : sprintf('+1 514 555 %04d', 4000 + $index),
                'description' => is_array($story)
                    ? (string) ($profile['internal_note'] ?? $story['archetype'] ?? '')
                    : 'Client actif du Grand Montréal avec un historique de services terrain.',
                'tags' => [
                    'scenario:boreal_proprete',
                    ...(array) (is_array($story) ? ($profile['tags'] ?? []) : [$companyName ? 'commercial' : 'residentiel']),
                ],
                'is_vip' => is_array($story) && (bool) ($story['is_vip'] ?? false),
                'logo' => $companyName ? '/images/presets/company-3.svg' : '/images/presets/avatar-'.(($index % 4) + 1).'.svg',
                'refer_by' => is_array($story) ? ($story['refer_by'] ?? 'Recommandation') : ['Web', 'Recommandation', 'Appel entrant'][$index % 3],
                'salutation' => 'Mr',
                'billing_same_as_physical' => true,
                'billing_mode' => $companyName ? 'monthly' : 'per_visit',
                'billing_cycle' => $companyName ? 'monthly' : 'on_completion',
                'billing_grouping' => $companyName ? 'by_customer' : 'per_work',
                'billing_delay_days' => $companyName ? 30 : 0,
                'is_active' => true,
            ]);
            $createdAt = $historyStart->addDays(min(50, $index * 2));
            $this->backdate('customers', (int) $customer->id, $createdAt);
            $customers->push($customer->fresh());
            if ($storyKey !== null) {
                $byStory->put($storyKey, $customer->fresh());
            }

            $siteDefinitions = is_array($story) ? array_values((array) ($story['sites'] ?? [])) : [];
            $configuredPropertyCount = is_array($story) ? max(1, (int) ($profile['property_count'] ?? 1)) : 1;
            while (count($siteDefinitions) < $configuredPropertyCount) {
                $siteNumber = count($siteDefinitions) + 1;
                $siteDefinitions[] = [
                    'label' => $configuredPropertyCount > 1 ? 'Site '.$siteNumber : ($companyName ? 'Site principal' : 'Résidence'),
                    'street1' => (100 + ($index * 5) + $siteNumber).', rue du Parc',
                    'city' => $index % 2 === 0 ? 'Montréal' : 'Longueuil',
                    'postal_code' => sprintf('H%1dA %1dB%1d', ($index % 8) + 1, ($siteNumber % 9) + 1, ($index % 7) + 1),
                ];
            }
            if ($siteDefinitions === []) {
                $siteDefinitions[] = [
                    'label' => $companyName ? 'Site principal' : 'Résidence',
                    'street1' => (100 + $index).', rue du Parc',
                    'city' => $index % 2 === 0 ? 'Montréal' : 'Longueuil',
                    'postal_code' => sprintf('H%1dA %1dB%1d', ($index % 8) + 1, ($index % 9) + 1, ($index % 7) + 1),
                ];
            }

            $customerProperties = collect();
            foreach ($siteDefinitions as $siteIndex => $site) {
                $property = $this->createProperty($customer, $site, $siteIndex === 0);
                $this->backdate('properties', (int) $property->id, $createdAt);
                $properties->push($property);
                $customerProperties->push($property);
            }
            $propertiesByCustomer->put((int) $customer->id, $customerProperties);
        }

        $siteIndex = 0;
        while ($properties->count() < $propertyTarget) {
            $customer = $customers[$siteIndex % $customers->count()];
            $siteNumber = $propertiesByCustomer->get((int) $customer->id, collect())->count() + 1;
            $property = $this->createProperty($customer, [
                'label' => 'Site '.$siteNumber,
                'street1' => (700 + $siteIndex).', boulevard Taschereau',
                'city' => $siteIndex % 2 === 0 ? 'Longueuil' : 'Brossard',
                'postal_code' => sprintf('J4%1d %1dA%1d', $siteIndex % 9, ($siteIndex % 8) + 1, ($siteIndex % 7) + 1),
            ], false);
            $this->backdate('properties', (int) $property->id, $historyStart->addDays($siteIndex % 60));
            $properties->push($property);
            $propertiesByCustomer->put(
                (int) $customer->id,
                $propertiesByCustomer->get((int) $customer->id, collect())->push($property),
            );
            $siteIndex++;
        }

        return compact('customers', 'properties') + [
            'customers_by_story' => $byStory,
            'properties_by_customer' => $propertiesByCustomer,
        ];
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function createProperty(Customer $customer, array $site, bool $default): Property
    {
        return Property::query()->create([
            'customer_id' => $customer->id,
            'type' => 'physical',
            'is_default' => $default,
            'country' => 'Canada',
            'street1' => (string) ($site['street1'] ?? '100, rue Principale'),
            'street2' => $site['label'] ?? null,
            'city' => (string) ($site['city'] ?? 'Longueuil'),
            'state' => 'QC',
            'zip' => (string) ($site['postal_code'] ?? 'J4H 2A9'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $customers
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @return array{
     *   requests: Collection<int, LeadRequest>,
     *   service_requests: Collection<int, ServiceRequest>,
     *   quotes: Collection<int, Quote>
     * }
     */
    private function createPipeline(
        DemoScenarioContext $context,
        array $blueprint,
        array $customers,
        Collection $teamMembers,
        Collection $services,
        int $requestTarget,
        int $quoteTarget,
    ): array {
        $customerModels = $customers['customers']->values();
        $storyCustomers = $customers['customers_by_story'];
        $propertiesByCustomer = $customers['properties_by_customer'];
        $storyDefinitions = collect((array) ($blueprint['client_stories'] ?? $blueprint['customer_stories'] ?? []))->keyBy('key');
        $requests = collect();
        $serviceRequests = collect();

        for ($index = 0; $index < $requestTarget; $index++) {
            $storyDefinition = $index < $storyDefinitions->count()
                ? $storyDefinitions->values()[$index]
                : null;
            $storyKey = is_array($storyDefinition) ? (string) $storyDefinition['key'] : null;
            $customer = $storyKey && $storyCustomers->has($storyKey)
                ? $storyCustomers->get($storyKey)
                : $customerModels[$index % $customerModels->count()];
            $property = $propertiesByCustomer->get((int) $customer->id, collect())->first();
            $service = $services->values()[$index % $services->count()];
            $monthsAgo = (int) (is_array($storyDefinition)
                ? data_get($storyDefinition, 'pipeline.months_ago', 10 - ($index % 10))
                : 10 - ($index % 10));
            $submittedAt = $context->referenceDate
                ->subMonths(max(0, $monthsAgo))
                ->addDays(($index * 3) % 20)
                ->setTime(9 + ($index % 7), 15);
            if ($submittedAt->gt($context->referenceDate)) {
                $submittedAt = $context->referenceDate->subDays($index % 4)->setTime(10, 0);
            }
            $status = $storyKey === 'atelier_mile_end'
                ? LeadRequest::STATUS_QUALIFIED
                : ($index % 11 === 10 ? LeadRequest::STATUS_LOST : LeadRequest::STATUS_WON);
            $title = is_array($storyDefinition)
                ? (string) data_get($storyDefinition, 'pipeline.request_title', 'Demande · '.$service->name)
                : 'Demande de service · '.$service->name;
            $request = LeadRequest::query()->create([
                'user_id' => $context->owner->id,
                'customer_id' => $customer->id,
                'converted_customer_id' => $status === LeadRequest::STATUS_WON ? $customer->id : null,
                'assigned_team_member_id' => $teamMembers->values()[$index % $teamMembers->count()]->id,
                'channel' => ['website', 'phone', 'referral', 'email'][$index % 4],
                'status' => $status,
                'service_type' => $service->name,
                'urgency' => $storyKey === 'gestion_loft_514' ? 'high' : ($index % 7 === 0 ? 'high' : 'normal'),
                'title' => $title,
                'description' => is_array($storyDefinition)
                    ? (string) data_get($storyDefinition, 'pipeline.request_description', data_get($storyDefinition, 'profile.internal_note', $storyDefinition['archetype'] ?? ''))
                    : 'Demande qualifiée pour un site du Grand Montréal.',
                'contact_name' => trim($customer->first_name.' '.$customer->last_name),
                'contact_email' => $customer->email,
                'contact_phone' => $customer->phone,
                'country' => 'Canada',
                'state' => 'QC',
                'city' => $property?->city ?? 'Longueuil',
                'street1' => $property?->street1,
                'street2' => $property?->street2,
                'postal_code' => $property?->zip,
                'is_serviceable' => true,
                'converted_at' => $status === LeadRequest::STATUS_WON ? $submittedAt->addDays(4)->utc() : null,
                'first_response_at' => $submittedAt->addHours(2)->utc(),
                'last_activity_at' => $submittedAt->addDays(4)->utc(),
                'sla_due_at' => $submittedAt->addDay()->utc(),
                'triage_priority' => $index % 7 === 0 ? 80 : 40,
                'risk_level' => $index % 11 === 10 ? 'high' : 'low',
                'status_updated_at' => $submittedAt->addDays(4)->utc(),
                'next_follow_up_at' => $storyKey === 'atelier_mile_end'
                    ? $context->referenceDate->addDay()->setTime(10, 30)->utc()
                    : null,
                'lost_reason' => $status === LeadRequest::STATUS_LOST ? 'budget' : null,
                'meta' => [
                    'scenario_key' => (string) $blueprint['key'],
                    'story_key' => $storyKey,
                    'site_label' => $property?->street2,
                ],
            ]);
            $this->backdate('requests', (int) $request->id, $submittedAt);
            $requests->push($request->fresh());

            $serviceStatus = match ($status) {
                LeadRequest::STATUS_WON => ServiceRequest::STATUS_ACCEPTED,
                LeadRequest::STATUS_LOST => ServiceRequest::STATUS_REFUSED,
                default => ServiceRequest::STATUS_IN_PROGRESS,
            };
            $serviceRequest = ServiceRequest::query()->create([
                'user_id' => $context->owner->id,
                'customer_id' => $customer->id,
                'prospect_id' => $request->id,
                'source' => 'scenario_pipeline',
                'channel' => $request->channel,
                'status' => $serviceStatus,
                'request_type' => $storyKey === 'construction_horizon' ? 'project_quote' : 'service_quote',
                'service_type' => $service->name,
                'title' => $request->title,
                'description' => $request->description,
                'requester_name' => $request->contact_name,
                'requester_email' => $request->contact_email,
                'requester_phone' => $request->contact_phone,
                'street1' => $request->street1,
                'street2' => $request->street2,
                'city' => $request->city,
                'state' => $request->state,
                'postal_code' => $request->postal_code,
                'country' => $request->country,
                'source_ref' => 'lead:'.$request->id,
                'source_meta' => ['scenario_key' => (string) $blueprint['key']],
                'submitted_at' => $submittedAt->utc(),
                'accepted_at' => $serviceStatus === ServiceRequest::STATUS_ACCEPTED ? $submittedAt->addDays(4)->utc() : null,
                'meta' => ['story_key' => $storyKey],
            ]);
            $this->backdate('service_requests', (int) $serviceRequest->id, $submittedAt);
            $serviceRequests->push($serviceRequest->fresh());
        }

        $quotes = collect();
        for ($index = 0; $index < $quoteTarget; $index++) {
            $storyDefinition = $index < $storyDefinitions->count()
                ? $storyDefinitions->values()[$index]
                : null;
            $storyKey = is_array($storyDefinition) ? (string) $storyDefinition['key'] : null;
            $customer = $storyKey && $storyCustomers->has($storyKey)
                ? $storyCustomers->get($storyKey)
                : $customerModels[$index % $customerModels->count()];
            $request = $requests->firstWhere('customer_id', $customer->id);
            $property = $propertiesByCustomer->get((int) $customer->id, collect())->first();
            $service = $services->values()[$index % $services->count()];
            $status = $storyKey === 'atelier_mile_end'
                ? 'sent'
                : ($index % 10 === 9 ? 'declined' : ($index % 8 === 7 ? 'sent' : 'accepted'));
            $override = $storyKey === 'construction_horizon'
                ? 7820.00
                : (float) (is_array($storyDefinition)
                    ? data_get($storyDefinition, 'pipeline.quote_total', 0)
                    : 0);
            $subtotal = $override > 0
                ? $override
                : round((float) $service->price * (1 + ($index % 4)), 2);
            $quoteDate = $request?->created_at
                ? CarbonImmutable::instance($request->created_at)->setTimezone($context->timezone)->addDays(2)
                : $this->historicalDate($context, $index, $quoteTarget);
            $acceptedAt = $status === 'accepted' ? $quoteDate->addDays(2) : null;
            $quote = Quote::query()->create([
                'user_id' => $context->owner->id,
                'job_title' => is_array($storyDefinition)
                    ? (string) data_get($storyDefinition, 'pipeline.quote_title', $service->name.' · '.$customer->company_name)
                    : $service->name.' · '.($customer->company_name ?: trim($customer->first_name.' '.$customer->last_name)),
                'status' => $status,
                'customer_id' => $customer->id,
                'property_id' => $property?->id,
                'request_id' => $request?->id,
                'prospect_id' => $request?->id,
                'total' => $subtotal,
                'subtotal' => $subtotal,
                'currency_code' => 'CAD',
                'initial_deposit' => $storyKey === 'construction_horizon' ? round($subtotal * 0.30, 2) : 0,
                'is_fixed' => ! in_array($storyKey, ['construction_horizon', 'groupe_lavoie_immeubles'], true),
                'notes' => is_array($storyDefinition)
                    ? (string) data_get($storyDefinition, 'pipeline.quote_notes', 'Portée détaillée et accès au site confirmés.')
                    : 'Produits standards, équipement et contrôle qualité inclus.',
                'messages' => 'Validité 30 jours. Les accès au site doivent être confirmés avant l’intervention.',
                'signed_at' => $acceptedAt?->utc(),
                'accepted_at' => $acceptedAt?->utc(),
                'last_sent_at' => $quoteDate->utc(),
                'last_viewed_at' => $quoteDate->addDay()->utc(),
                'next_follow_up_at' => $status === 'sent' ? $context->referenceDate->addDays(2)->utc() : null,
                'follow_up_state' => $status === 'sent' ? 'due' : 'completed',
                'follow_up_count' => $status === 'sent' ? 1 : 0,
                'recovery_priority' => $storyKey === 'atelier_mile_end' ? 80 : 20,
            ]);
            $quote->syncProductLines([
                $service->id => [
                    'quantity' => 1,
                    'price' => $subtotal,
                    'description' => $service->description,
                    'total' => $subtotal,
                ],
            ]);
            $this->backdate('quotes', (int) $quote->id, $quoteDate);
            DB::table('quote_products')->where('quote_id', $quote->id)->update([
                'created_at' => $quoteDate->utc(),
                'updated_at' => $quoteDate->utc(),
            ]);
            $quotes->push($quote->fresh());
        }

        $atelier = $storyCustomers->get('atelier_mile_end');
        $atelierRequest = $atelier ? $requests->firstWhere('customer_id', $atelier->id) : null;
        if ($atelier && $atelierRequest) {
            $followUp = Task::query()->create([
                'account_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'assigned_team_member_id' => $teamMembers->get('mariam_diallo')?->id ?? $teamMembers->first()?->id,
                'customer_id' => $atelier->id,
                'request_id' => $atelierRequest->id,
                'title' => 'Relancer Atelier Mile End après le devis',
                'description' => 'Valider la fréquence des passages du soir et obtenir la décision sur le devis commercial.',
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_HIGH,
                'billable' => false,
                'due_date' => $context->referenceDate->addDays(2)->toDateString(),
                'start_time' => '10:00:00',
                'end_time' => '10:30:00',
            ]);
            $this->backdate('tasks', (int) $followUp->id, $context->referenceDate->subDay()->setTime(14, 0));
        }

        return compact('requests', 'serviceRequests', 'quotes') + ['service_requests' => $serviceRequests];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $customers
     * @param  Collection<int, Quote>  $quotes
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  Collection<string, Product>  $services
     * @return Collection<int, Work>
     */
    private function createWorks(
        DemoScenarioContext $context,
        array $blueprint,
        array $customers,
        Collection $quotes,
        Collection $teamMembers,
        Collection $services,
        int $target,
    ): Collection {
        $works = collect();
        $storyCustomers = $customers['customers_by_story'];
        $customerPool = $customers['customers']->values();
        foreach (['groupe_lavoie_immeubles', 'clinique_du_parc', 'camille_fortin', 'gestion_loft_514'] as $storyKey) {
            if ($storyCustomers->has($storyKey)) {
                $customerPool = $customerPool->concat(array_fill(0, 5, $storyCustomers->get($storyKey)));
            }
        }
        $customerPool = $customerPool->values();
        $narratives = $this->narrativeWorkDefinitions($context);
        $futureCount = max(4, (int) round($target * 0.06));

        for ($index = 0; $index < $target; $index++) {
            $narrative = $narratives[$index] ?? null;
            $storyKey = is_array($narrative) ? (string) $narrative['story_key'] : null;
            $customer = $storyKey && $storyCustomers->has($storyKey)
                ? $storyCustomers->get($storyKey)
                : $customerPool[$index % $customerPool->count()];
            $service = is_array($narrative) && $services->has((string) $narrative['service_key'])
                ? $services->get((string) $narrative['service_key'])
                : $services->values()[$index % $services->count()];
            $isFuture = ! is_array($narrative) && $index >= $target - $futureCount;
            $start = is_array($narrative)
                ? $narrative['date']
                : ($isFuture
                    ? $context->referenceDate->addDays(1 + (($index - ($target - $futureCount)) * 4))
                    : $this->historicalDate($context, $index, max(1, $target - $futureCount)));
            $durationMinutes = $this->serviceDurationMinutes($service);
            $startHour = $index % 3 === 0 ? 18 : 8 + ($index % 3);
            $startsAt = $start->setTime($startHour, ($index % 2) * 30);
            $endsAt = $startsAt->addMinutes($durationMinutes);
            $status = is_array($narrative)
                ? (string) $narrative['status']
                : ($isFuture
                    ? Work::STATUS_SCHEDULED
                    : match (true) {
                        $index % 31 === 0 => Work::STATUS_CANCELLED,
                        $index % 19 === 0 => Work::STATUS_PENDING_REVIEW,
                        $index % 11 === 0 => Work::STATUS_VALIDATED,
                        default => Work::STATUS_COMPLETED,
                    });
            $quote = $quotes
                ->where('customer_id', $customer->id)
                ->where('status', 'accepted')
                ->first();
            $subtotal = is_array($narrative) && isset($narrative['subtotal'])
                ? (float) $narrative['subtotal']
                : (float) $service->price;
            $title = is_array($narrative)
                ? (string) $narrative['title']
                : $service->name.' · '.($customer->company_name ?: trim($customer->first_name.' '.$customer->last_name));
            $createdAt = $isFuture
                ? $context->referenceDate->subDays(3 + ($index % 7))
                : $start->subDays(5 + ($index % 9));
            $work = Work::query()->create([
                'user_id' => $context->owner->id,
                'customer_id' => $customer->id,
                'quote_id' => $quote?->id,
                'job_title' => $title,
                'instructions' => is_array($narrative)
                    ? (string) ($narrative['instructions'] ?? 'Suivre la procédure du site et documenter le contrôle qualité.')
                    : 'Confirmer l’accès, exécuter la liste de contrôle et joindre une preuve avant/après.',
                'start_date' => $start->toDateString(),
                'end_date' => $endsAt->toDateString(),
                'start_time' => $startsAt->format('H:i:s'),
                'end_time' => $endsAt->format('H:i:s'),
                'is_all_day' => false,
                'later' => false,
                'ends' => 'After',
                'frequencyNumber' => 1,
                'frequency' => $customer->company_name ? 'Weekly' : 'Biweekly',
                'totalVisits' => $customer->company_name ? 12 : 2,
                'repeatsOn' => [$start->dayOfWeekIso],
                'type' => $customer->company_name ? 'recurring_site_service' : 'residential_service',
                'category' => (string) data_get($service->tags, 3, 'nettoyage'),
                'status' => $status,
                'completed_at' => in_array($status, Work::COMPLETED_STATUSES, true) ? $endsAt->utc() : null,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'billing_mode' => $customer->company_name ? 'monthly' : 'per_visit',
                'billing_cycle' => $customer->company_name ? 'monthly' : 'on_completion',
                'billing_grouping' => $customer->company_name ? 'by_customer' : 'per_work',
                'billing_delay_days' => $customer->company_name ? 30 : 0,
                'billing_date_rule' => $customer->company_name ? 'month_end' : 'completion_date',
            ]);
            $this->backdate('works', (int) $work->id, $createdAt);
            $work->products()->attach($service->id, [
                'quote_id' => $quote?->id,
                'quantity' => 1,
                'price' => $subtotal,
                'description' => $service->description,
                'source_details' => json_encode([
                    'scenario_key' => (string) $blueprint['key'],
                    'service_key' => $this->serviceKey($service),
                ], JSON_THROW_ON_ERROR),
                'total' => $subtotal,
            ]);
            $lead = $teamMembers->values()[$index % $teamMembers->count()];
            $support = $teamMembers->values()[($index + 1) % $teamMembers->count()];
            $work->teamMembers()->attach([
                $lead->id => ['role' => 'lead'],
                $support->id => ['role' => 'support'],
            ]);

            if ($quote && ! $quote->work_id) {
                $quote->forceFill(['work_id' => $work->id])->save();
            }
            $works->push($work->fresh());
        }

        return $works;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function narrativeWorkDefinitions(DemoScenarioContext $context): array
    {
        return [
            [
                'story_key' => 'groupe_lavoie_immeubles',
                'service_key' => 'building_common_areas',
                'date' => $context->referenceDate->subMonths(7)->startOfMonth()->addDays(16),
                'status' => Work::STATUS_DISPUTE,
                'title' => 'Incident hivernal · Résidences Lavoie — site Papineau',
                'instructions' => 'Traces de calcium signalées dans le hall. Suspendre la validation et déclencher une inspection qualité.',
            ],
            [
                'story_key' => 'groupe_lavoie_immeubles',
                'service_key' => 'building_common_areas',
                'date' => $context->referenceDate->subMonths(7)->startOfMonth()->addDays(17),
                'status' => Work::STATUS_VALIDATED,
                'title' => 'Reprise qualité sous 24 h · Résidences Lavoie',
                'instructions' => 'Reprise sans frais, photos avant/après et appel de confirmation avec le gestionnaire.',
                'subtotal' => 0,
            ],
            [
                'story_key' => 'construction_horizon',
                'service_key' => 'complete_post_construction',
                'date' => $context->referenceDate->subMonths(2)->startOfMonth()->addDays(9),
                'status' => Work::STATUS_COMPLETED,
                'title' => 'Construction Horizon · Phase 1 — dépoussiérage',
                'instructions' => 'Dépoussiérage haut vers bas, évacuation des résidus fins et preuve photo par zone.',
                'subtotal' => 3120,
            ],
            [
                'story_key' => 'construction_horizon',
                'service_key' => 'complete_post_construction',
                'date' => $context->referenceDate->subMonths(2)->startOfMonth()->addDays(13),
                'status' => Work::STATUS_COMPLETED,
                'title' => 'Construction Horizon · Phase 2 — remise finale',
                'instructions' => 'Nettoyage final avant livraison. Valider les zones avec le contremaître.',
                'subtotal' => 3700,
            ],
            [
                'story_key' => 'construction_horizon',
                'service_key' => 'commercial_windows',
                'date' => $context->referenceDate->subMonths(2)->startOfMonth()->addDays(15),
                'status' => Work::STATUS_PENDING_REVIEW,
                'title' => 'Construction Horizon · Ajout de portée — vitres',
                'instructions' => 'Ajout signé après l’évaluation initiale. Validation finale du client requise.',
                'subtotal' => 1000,
            ],
            [
                'story_key' => 'elodie_nguyen',
                'service_key' => 'move_in_out_cleaning',
                'date' => $context->referenceDate->subMonth()->startOfMonth()->addDays(8),
                'status' => Work::STATUS_DISPUTE,
                'title' => 'Déménagement Élodie Nguyen · contrôle incomplet',
                'instructions' => 'Le four a été omis au contrôle final. Incident client ouvert et priorité urgente.',
            ],
            [
                'story_key' => 'elodie_nguyen',
                'service_key' => 'oven_fridge_addon',
                'date' => $context->referenceDate->subMonth()->startOfMonth()->addDays(9),
                'status' => Work::STATUS_VALIDATED,
                'title' => 'Reprise urgente · Élodie Nguyen',
                'instructions' => 'Reprise gratuite le lendemain, appel de suivi et confirmation de satisfaction.',
                'subtotal' => 0,
            ],
            [
                'story_key' => 'gestion_loft_514',
                'service_key' => 'short_term_rental_turnover',
                'date' => $context->referenceDate->subMonth()->startOfMonth()->addDays(19),
                'status' => Work::STATUS_COMPLETED,
                'title' => 'Loft 514 · Rotation réaffectée en urgence',
                'instructions' => 'Absence imprévue. Samuel reprend le site; client avisé et arrivée voyageurs protégée.',
            ],
            [
                'story_key' => 'clinique_du_parc',
                'service_key' => 'high_touch_disinfection',
                'date' => $context->referenceDate,
                'status' => Work::STATUS_COMPLETED,
                'title' => 'Clinique du Parc · désinfection du soir',
                'instructions' => 'Respecter le protocole clinique, consigner les zones et fermer le site à 22 h.',
            ],
        ];
    }

    /**
     * @param  Collection<int, Work>  $works
     * @param  Collection<string, TeamMember>  $teamMembers
     * @param  array{services: Collection<string, Product>, products: Collection<string, Product>}  $catalog
     * @return array<string, Collection<int, mixed>>
     */
    private function createExecutionHistory(
        DemoScenarioContext $context,
        Collection $works,
        Collection $teamMembers,
        array $catalog,
        int $taskTarget,
        int $checklistTarget,
        int $mediaTarget,
        int $taskMaterialTarget,
        int $taskStatusHistoryTarget,
        int $ratingTarget,
    ): array {
        if ($taskTarget < 1 || $checklistTarget < $works->count()) {
            throw new RuntimeException('Field operations execution targets are too small for the generated works.');
        }

        $tasks = Task::query()->forAccount((int) $context->owner->id)->orderBy('id')->get();
        $remainingTasks = $taskTarget - $tasks->count();
        if ($remainingTasks < 0) {
            throw new RuntimeException('Field operations pipeline created more tasks than the configured target.');
        }
        $taskTitles = [
            'Confirmer l’accès et les consignes du site',
            'Préparer les produits et équipements',
            'Exécuter la zone prioritaire',
            'Compléter le contrôle qualité',
            'Joindre les preuves avant et après',
            'Aviser le client et fermer le site',
        ];

        for ($index = 0; $index < $remainingTasks; $index++) {
            $work = $works[$index % $works->count()];
            $assignee = $work->teamMembers()->orderBy('team_members.id')->get()->values()[$index % 2]
                ?? $teamMembers->values()[$index % $teamMembers->count()];
            $workDate = CarbonImmutable::parse($work->start_date->toDateString(), $context->timezone);
            $workCompleted = in_array($work->status, Work::COMPLETED_STATUSES, true);
            $status = $workCompleted
                ? Task::STATUS_DONE
                : ($work->status === Work::STATUS_CANCELLED
                    ? Task::STATUS_CANCELLED
                    : ($index % 4 === 0 ? Task::STATUS_IN_PROGRESS : Task::STATUS_TODO));
            $service = $work->products()->first();
            $createdAt = $workDate->isAfter($context->referenceDate)
                ? $context->referenceDate->subDays(4 + ($index % 5))
                : $workDate->subDays(4 + ($index % 3));
            $task = Task::query()->create([
                'account_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'assigned_team_member_id' => $assignee?->id,
                'customer_id' => $work->customer_id,
                'product_id' => $service?->id,
                'work_id' => $work->id,
                'title' => $taskTitles[$index % count($taskTitles)],
                'description' => $work->status === Work::STATUS_DISPUTE
                    ? 'Action prioritaire liée au dossier qualité; documenter chaque étape.'
                    : 'Étape opérationnelle du chantier générée dans le scénario Boréal Propreté.',
                'status' => $status,
                'priority' => $work->status === Work::STATUS_DISPUTE
                    ? Task::PRIORITY_URGENT
                    : ($index % 9 === 0 ? Task::PRIORITY_HIGH : Task::PRIORITY_NORMAL),
                'billable' => false,
                'due_date' => $workDate->toDateString(),
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'completed_at' => $status === Task::STATUS_DONE
                    ? CarbonImmutable::parse($workDate->toDateString().' '.$work->end_time, $context->timezone)->utc()
                    : null,
                'cancelled_at' => $status === Task::STATUS_CANCELLED ? $workDate->subDay()->utc() : null,
                'completion_reason' => $status === Task::STATUS_DONE ? 'work_completed' : null,
                'cancellation_reason' => $status === Task::STATUS_CANCELLED ? 'client_rescheduled' : null,
                'client_notified_at' => str_contains((string) $work->job_title, 'réaffectée')
                    ? $workDate->subHours(2)->utc()
                    : null,
            ]);
            $this->backdate('tasks', (int) $task->id, $createdAt);
            $tasks->push($task->fresh());
        }

        $checklistItems = collect();
        $checklistLabels = [
            'Accès au site confirmé',
            'Zones prioritaires complétées',
            'Surfaces et points de contact contrôlés',
            'Planchers et déchets contrôlés',
            'Anomalies consignées',
            'Preuve finale ajoutée',
        ];
        for ($index = 0; $index < $checklistTarget; $index++) {
            $work = $works[$index % $works->count()];
            $workDate = CarbonImmutable::parse($work->start_date->toDateString(), $context->timezone);
            $done = in_array($work->status, Work::COMPLETED_STATUSES, true)
                && ! ($work->status === Work::STATUS_PENDING_REVIEW && $index % 5 === 0);
            $item = WorkChecklistItem::query()->create([
                'work_id' => $work->id,
                'quote_id' => $work->quote_id,
                'title' => $checklistLabels[$index % count($checklistLabels)],
                'description' => $work->status === Work::STATUS_DISPUTE
                    ? 'Élément revu dans le cadre du protocole de récupération qualité.'
                    : 'Procédure standard Boréal Propreté.',
                'status' => $done ? 'done' : 'pending',
                'sort_order' => intdiv($index, max(1, $works->count())),
                'completed_at' => $done ? $workDate->setTimeFromTimeString((string) $work->end_time)->utc() : null,
            ]);
            $createdAt = $workDate->isAfter($context->referenceDate)
                ? $context->referenceDate->subDays(3)
                : $workDate->subDays(2);
            $this->backdate('work_checklist_items', (int) $item->id, $createdAt);
            $checklistItems->push($item->fresh());
        }

        $media = collect();
        $mediaTypes = ['before', 'execution', 'after'];
        for ($index = 0; $index < $mediaTarget; $index++) {
            $work = $works[$index % $works->count()];
            $workDate = CarbonImmutable::parse($work->start_date->toDateString(), $context->timezone);
            $createdAt = $workDate->isAfter($context->referenceDate)
                ? $context->referenceDate->subDays(2)
                : $workDate->setTime(12, $index % 60);
            $proof = WorkMedia::query()->create([
                'work_id' => $work->id,
                'user_id' => $context->owner->id,
                'type' => $mediaTypes[$index % count($mediaTypes)],
                'path' => url('/images/landing/stock/cleaning-team-office.jpg'),
                'meta' => [
                    'scenario_key' => 'boreal_proprete_services',
                    'note' => $work->status === Work::STATUS_DISPUTE
                        ? 'Preuve liée au contrôle et à la reprise qualité.'
                        : 'Preuve de passage générée pour la démonstration.',
                    'zone' => ['entrée', 'cuisine', 'sanitaires', 'planchers'][$index % 4],
                ],
            ]);
            $this->backdate('work_media', (int) $proof->id, $createdAt);
            $media->push($proof->fresh());
        }

        $taskMaterials = collect();
        $productModels = $catalog['products']->values();
        for ($index = 0; $index < $taskMaterialTarget; $index++) {
            $task = $tasks[$index % $tasks->count()];
            $product = $productModels[$index % $productModels->count()];
            $sourceService = $task->product_id ? $catalog['services']->firstWhere('id', $task->product_id) : null;
            $material = TaskMaterial::query()->create([
                'task_id' => $task->id,
                'product_id' => $product->id,
                'source_service_id' => $sourceService?->id,
                'label' => $product->name,
                'description' => 'Quantité prévue pour cette étape du chantier.',
                'unit' => $product->unit,
                'quantity' => $index % 5 === 0 ? 2 : 1,
                'unit_price' => $product->cost_price,
                'billable' => false,
                'sort_order' => intdiv($index, max(1, $tasks->count())),
                'stock_moved_at' => $task->isDone() ? $task->completed_at : null,
            ]);
            $taskMaterials->push($material);
        }

        $statusHistories = collect();
        for ($index = 0; $index < $taskStatusHistoryTarget; $index++) {
            $task = $tasks[$index % $tasks->count()];
            $toStatus = $index < $tasks->count()
                ? $task->status
                : ($task->isClosed() ? $task->status : Task::STATUS_IN_PROGRESS);
            $history = TaskStatusHistory::query()->create([
                'task_id' => $task->id,
                'user_id' => $context->owner->id,
                'from_status' => $index < $tasks->count() ? null : Task::STATUS_TODO,
                'to_status' => $toStatus,
                'timing_status' => $task->due_date?->isBefore($context->referenceDate) && ! $task->isClosed()
                    ? 'late'
                    : 'on_time',
                'due_date' => $task->due_date,
                'completed_at' => $task->completed_at,
                'reason_code' => str_contains((string) $task->description, 'qualité') ? 'quality_recovery' : null,
                'note' => 'Transition conservée dans l’historique de démonstration.',
                'action' => 'scenario',
                'metadata' => ['scenario_key' => 'boreal_proprete_services'],
            ]);
            $createdAt = $task->created_at
                ? CarbonImmutable::instance($task->created_at)->addMinutes(15 + ($index % 120))
                : $context->referenceDate->subDays(5);
            if ($createdAt->gt($context->referenceDate->endOfDay())) {
                $createdAt = $context->referenceDate->subMinutes($index % 120);
            }
            $this->backdate('task_status_histories', (int) $history->id, $createdAt);
            $statusHistories->push($history->fresh());
        }

        $ratings = collect();
        $rateableWorks = $works->filter(
            fn (Work $work): bool => in_array($work->status, Work::COMPLETED_STATUSES, true),
        )->values();
        if ($ratingTarget > $rateableWorks->count()) {
            throw new RuntimeException('Field operations rating target exceeds completed work count.');
        }
        for ($index = 0; $index < $ratingTarget; $index++) {
            $work = $rateableWorks[$index];
            $userId = $teamMembers->values()[$index % $teamMembers->count()]->user_id;
            $rating = WorkRating::query()->create([
                'work_id' => $work->id,
                'user_id' => $userId,
                'rating' => $work->customer?->company_name ? 4 + ($index % 2) : 5,
                'feedback' => str_contains((string) $work->job_title, 'Reprise')
                    ? 'La correction a été rapide et le suivi très professionnel.'
                    : 'Équipe ponctuelle, travail constant et site bien documenté.',
            ]);
            $workDate = CarbonImmutable::parse($work->start_date->toDateString(), $context->timezone);
            $this->backdate('work_ratings', (int) $rating->id, $workDate->addDay());
            $ratings->push($rating->fresh());
        }

        return [
            'tasks' => $tasks->values(),
            'work_checklist_items' => $checklistItems,
            'work_media' => $media,
            'task_materials' => $taskMaterials,
            'task_status_histories' => $statusHistories,
            'reviews' => $ratings,
        ];
    }

    /**
     * @param  array<string, mixed>  $customers
     * @param  Collection<int, Work>  $works
     * @param  Collection<int, Quote>  $quotes
     * @param  Collection<string, TeamMember>  $teamMembers
     * @return array{invoices: Collection<int, Invoice>, payments: Collection<int, Payment>, transactions: Collection<int, Transaction>}
     */
    private function createBillingHistory(
        DemoScenarioContext $context,
        array $customers,
        Collection $works,
        Collection $quotes,
        Collection $teamMembers,
        int $invoiceTarget,
        int $paymentTarget,
    ): array {
        $eligibleWorks = $works
            ->filter(fn (Work $work): bool => in_array($work->status, Work::COMPLETED_STATUSES, true))
            ->sortBy(fn (Work $work): string => $work->start_date?->toDateString() ?? '')
            ->values();
        if ($invoiceTarget > $eligibleWorks->count()) {
            throw new RuntimeException('Field operations invoice target exceeds billable completed works.');
        }

        $priorityWorks = $eligibleWorks
            ->filter(fn (Work $work): bool => str_contains((string) $work->job_title, 'Construction Horizon')
                || str_contains((string) $work->job_title, 'Élodie Nguyen')
                || str_contains((string) $work->job_title, 'Clinique du Parc')
                || str_contains((string) $work->job_title, 'Résidences Lavoie'));
        $invoiceWorks = $priorityWorks
            ->concat($eligibleWorks)
            ->unique('id')
            ->values()
            ->take($invoiceTarget);
        if ($invoiceWorks->count() < $invoiceTarget) {
            throw new RuntimeException('Field operations could not select enough unique works for billing.');
        }

        $invoices = collect();
        foreach ($invoiceWorks as $index => $work) {
            $workDate = CarbonImmutable::parse($work->start_date->toDateString(), $context->timezone);
            $invoiceDate = $workDate->addDay()->setTime(9, $index % 60);
            if ($invoiceDate->gt($context->referenceDate->endOfDay())) {
                $invoiceDate = $context->referenceDate->setTime(9, $index % 60);
            }
            $isConstruction = str_contains((string) $work->job_title, 'Ajout de portée — vitres');
            $isElodie = str_contains((string) $work->job_title, 'Élodie Nguyen');
            $statusBucket = $index % 100;
            $status = match (true) {
                $isConstruction => 'partial',
                $isElodie => 'paid',
                $statusBucket < 70 => 'paid',
                $statusBucket < 82 => 'partial',
                $statusBucket < 92 => 'sent',
                default => 'overdue',
            };
            $subtotal = $isConstruction
                ? 7820.00
                : max(0, round((float) $work->total, 2));
            if ($subtotal <= 0) {
                $subtotal = 95.00;
            }
            $taxTotal = round($subtotal * self::TAX_RATE, 2);
            $total = round($subtotal + $taxTotal, 2);
            $invoice = Invoice::query()->create([
                'work_id' => $work->id,
                'customer_id' => $work->customer_id,
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'approved_by_user_id' => $context->owner->id,
                'status' => $status,
                'approval_status' => FinanceApprovalService::APPROVAL_STATUS_APPROVED,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'currency_code' => 'CAD',
                'source' => 'boreal_proprete_scenario',
                'billing_snapshot' => [
                    'scenario_key' => 'boreal_proprete_services',
                    'tax_rate' => 14.975,
                    'taxes_included' => false,
                    'billing_cycle' => $work->billing_cycle,
                    'billing_grouping' => $work->billing_grouping,
                    'deposit_applied' => $isConstruction ? 2346.00 : 0,
                ],
                'customer_snapshot' => [
                    'name' => $work->customer?->company_name
                        ?: trim((string) $work->customer?->first_name.' '.(string) $work->customer?->last_name),
                    'email' => $work->customer?->email,
                ],
                'approved_at' => $invoiceDate->utc(),
            ]);
            $this->backdate('invoices', (int) $invoice->id, $invoiceDate);
            $assignee = $teamMembers->values()[$index % $teamMembers->count()];
            $item = InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'work_id' => $work->id,
                'assigned_team_member_id' => $assignee->id,
                'title' => $work->job_title,
                'description' => $isConstruction
                    ? 'Projet post-chantier, ajout de vitres et remise finale selon le devis accepté.'
                    : 'Intervention exécutée avec contrôle qualité et preuve de passage.',
                'scheduled_date' => $work->start_date,
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'assignee_name' => $assignee->user?->name,
                'task_status' => 'done',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'currency_code' => 'CAD',
                'total' => $subtotal,
                'meta' => [
                    'scenario_key' => 'boreal_proprete_services',
                    'quality_proof_count' => $work->media()->count(),
                ],
            ]);
            $this->backdate('invoice_items', (int) $item->id, $invoiceDate);
            $invoices->push($invoice->fresh(['payments']));
        }

        $payableInvoices = $invoices
            ->filter(fn (Invoice $invoice): bool => in_array($invoice->status, ['paid', 'partial'], true))
            ->values();
        $settledTarget = max(0, $paymentTarget - 1);
        if ($payableInvoices->count() > $settledTarget) {
            throw new RuntimeException('Field operations payment target cannot settle every paid or partial invoice.');
        }
        $paymentCounts = array_fill(0, $payableInvoices->count(), 1);
        for ($extra = $settledTarget - $payableInvoices->count(), $index = 0; $extra > 0; $extra--, $index++) {
            $paymentCounts[$index % count($paymentCounts)]++;
        }

        $payments = collect();
        foreach ($payableInvoices as $invoiceIndex => $invoice) {
            $invoiceDate = CarbonImmutable::instance($invoice->created_at)->setTimezone($context->timezone);
            $settledTotal = $invoice->status === 'partial'
                ? round((float) $invoice->total * ($invoice->customer?->company_name === 'Construction Horizon' ? 0.56 : 0.45), 2)
                : round((float) $invoice->total, 2);
            $parts = $this->splitMoney($settledTotal, $paymentCounts[$invoiceIndex]);
            foreach ($parts as $partIndex => $amount) {
                $paidAt = $invoiceDate->addDays(min(25, 2 + ($partIndex * 5)));
                if ($paidAt->gt($context->referenceDate->endOfDay())) {
                    $paidAt = $invoiceDate->addHour()->addMinutes($partIndex);
                }
                $payment = Payment::query()->create([
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'user_id' => $context->owner->id,
                    'amount' => $amount,
                    'currency_code' => 'CAD',
                    'charged_total' => $amount,
                    'method' => ['bank_transfer', 'card', 'cheque', 'cash'][($invoiceIndex + $partIndex) % 4],
                    'provider' => 'demo',
                    'status' => Payment::STATUS_COMPLETED,
                    'reference' => sprintf('BOR-PAY-%04d-%d', $invoiceIndex + 1, $partIndex + 1),
                    'notes' => $invoice->status === 'partial'
                        ? 'Paiement partiel conservé pour le suivi des comptes clients.'
                        : 'Paiement associé à une intervention Boréal Propreté.',
                    'paid_at' => $paidAt->utc(),
                ]);
                $this->backdate('payments', (int) $payment->id, $paidAt);
                $payments->push($payment->fresh());
            }
        }

        $elodie = $customers['customers_by_story']->get('elodie_nguyen');
        $elodieInvoice = $elodie
            ? $invoices->firstWhere('customer_id', $elodie->id)
            : null;
        $refundBase = $elodieInvoice?->created_at
            ? CarbonImmutable::instance($elodieInvoice->created_at)->setTimezone($context->timezone)->addDays(3)
            : $context->referenceDate->subDays(20);
        if ($refundBase->gt($context->referenceDate)) {
            $refundBase = $context->referenceDate->subDay();
        }
        $refund = Payment::query()->create([
            'invoice_id' => $elodieInvoice?->id,
            'customer_id' => $elodie?->id,
            'user_id' => $context->owner->id,
            'amount' => 45.00,
            'currency_code' => 'CAD',
            'method' => 'credit_note',
            'provider' => 'demo',
            'status' => Payment::STATUS_REFUNDED,
            'reference' => 'BOREAL-ELODIE-CREDIT',
            'notes' => 'Ajustement commercial après la reprise du nettoyage du four.',
            'paid_at' => $refundBase->utc(),
        ]);
        $this->backdate('payments', (int) $refund->id, $refundBase);
        $payments->push($refund->fresh());

        if ($payments->count() !== $paymentTarget) {
            throw new RuntimeException(sprintf(
                'Field operations payment mismatch: expected %d, generated %d.',
                $paymentTarget,
                $payments->count(),
            ));
        }

        $transactions = collect();
        $acceptedQuotes = $quotes
            ->where('status', 'accepted')
            ->sortByDesc(fn (Quote $quote): bool => $quote->customer?->company_name === 'Construction Horizon')
            ->values()
            ->take(4);
        foreach ($acceptedQuotes as $index => $quote) {
            $isConstruction = $quote->customer?->company_name === 'Construction Horizon';
            $amount = $isConstruction
                ? 2346.00
                : round((float) $quote->total * 0.20, 2);
            $paidAt = CarbonImmutable::instance($quote->accepted_at ?? $quote->created_at)
                ->setTimezone($context->timezone)
                ->addDay();
            $transaction = Transaction::query()->create([
                'quote_id' => $quote->id,
                'work_id' => $quote->work_id,
                'customer_id' => $quote->customer_id,
                'user_id' => $context->owner->id,
                'amount' => $amount,
                'type' => 'deposit',
                'method' => 'bank_transfer',
                'status' => 'completed',
                'reference' => $isConstruction ? 'BOREAL-HORIZON-DEPOT-30' : sprintf('BOR-DEP-%04d', $index + 1),
                'notes' => $isConstruction
                    ? 'Acompte de 30 % reçu avant le démarrage du chantier.'
                    : 'Acompte de mobilisation.',
                'paid_at' => $paidAt->utc(),
            ]);
            $this->backdate('transactions', (int) $transaction->id, $paidAt);
            $transactions->push($transaction->fresh());
        }

        return compact('invoices', 'payments', 'transactions');
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Work>  $works
     * @param  Collection<string, TeamMember>  $teamMembers
     * @return Collection<int, Expense>
     */
    private function createExpenseHistory(
        DemoScenarioContext $context,
        array $blueprint,
        Collection $customers,
        Collection $works,
        Collection $teamMembers,
        int $target,
    ): Collection {
        $templates = collect((array) ($blueprint['expense_templates'] ?? []))->values();
        if ($templates->isEmpty()) {
            throw new RuntimeException('Field operations blueprint must define expense templates.');
        }

        $expenses = collect();
        for ($index = 0; $index < $target; $index++) {
            $template = $templates[$index % $templates->count()];
            $date = $this->historicalDate($context, $index, $target)->startOfDay();
            $range = (array) ($template['amount_range'] ?? [100, 100]);
            $minimum = (float) ($range[0] ?? 100);
            $maximum = (float) ($range[1] ?? $minimum);
            $ratio = (($index * 37) % 101) / 100;
            $subtotal = round($minimum + (($maximum - $minimum) * $ratio), 2);
            $tax = round($subtotal * self::TAX_RATE, 2);
            $total = round($subtotal + $tax, 2);
            $status = match (true) {
                $index % 17 === 0 => Expense::STATUS_REVIEW_REQUIRED,
                $index % 13 === 0 => Expense::STATUS_DUE,
                default => Expense::STATUS_PAID,
            };
            $reimbursable = (bool) ($template['reimbursable'] ?? false);
            $member = $teamMembers->values()[$index % $teamMembers->count()];
            $work = $works[$index % $works->count()];
            $expense = Expense::query()->create([
                'user_id' => $context->owner->id,
                'created_by_user_id' => $context->owner->id,
                'approved_by_user_id' => $status === Expense::STATUS_PAID ? $context->owner->id : null,
                'paid_by_user_id' => $status === Expense::STATUS_PAID ? $context->owner->id : null,
                'team_member_id' => $reimbursable ? $member->id : null,
                'customer_id' => $index % 8 === 0 ? $customers[$index % $customers->count()]->id : null,
                'work_id' => $index % 8 === 0 ? $work->id : null,
                'title' => (string) $template['name'],
                'category_key' => (string) $template['category'],
                'supplier_name' => $this->supplierForExpense((string) $template['category']),
                'reference_number' => sprintf('BOR-EXP-%05d', $index + 1),
                'currency_code' => 'CAD',
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'expense_date' => $date->toDateString(),
                'due_date' => $date->addDays(15)->toDateString(),
                'paid_date' => $status === Expense::STATUS_PAID ? $date->addDays(3)->toDateString() : null,
                'approved_at' => $status === Expense::STATUS_PAID ? $date->addDays(2)->utc() : null,
                'payment_method' => (string) ($template['payment_method'] ?? 'card'),
                'status' => $status,
                'reimbursable' => $reimbursable,
                'reimbursement_status' => $reimbursable && $status === Expense::STATUS_PAID
                    ? Expense::REIMBURSEMENT_STATUS_REIMBURSED
                    : ($reimbursable ? Expense::REIMBURSEMENT_STATUS_PENDING : Expense::REIMBURSEMENT_STATUS_NOT_APPLICABLE),
                'is_recurring' => in_array((string) ($template['frequency'] ?? ''), ['monthly', 'weekly', 'biweekly'], true),
                'recurrence_frequency' => (string) ($template['frequency'] ?? '') === 'monthly' ? 'monthly' : null,
                'recurrence_interval' => 1,
                'description' => 'Dépense opérationnelle reliée aux équipes et aux sites de Boréal Propreté.',
                'notes' => $index % 19 === 0
                    ? 'Location urgente d’un équipement après une panne terrain.'
                    : 'Pièce justificative vérifiée dans le scénario.',
                'meta' => [
                    'scenario_key' => (string) $blueprint['key'],
                    'template_key' => (string) $template['key'],
                ],
            ]);
            $this->backdate('expenses', (int) $expense->id, $date);
            $expenses->push($expense->fresh());
        }

        return $expenses;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     */
    private function createActionNotifications(DemoScenarioContext $context, array $blueprint): int
    {
        $templates = [
            [
                'type' => 'quality',
                'severity' => 'warning',
                'title' => 'Contrôle qualité à valider',
                'message' => 'La reprise du site Lavoie attend la validation finale de la coordonnatrice qualité.',
                'action_url' => '/jobs',
            ],
            [
                'type' => 'pipeline',
                'severity' => 'info',
                'title' => 'Devis à relancer',
                'message' => 'Atelier Mile End doit être relancé dans les deux prochains jours.',
                'action_url' => '/quotes',
            ],
            [
                'type' => 'finance',
                'severity' => 'warning',
                'title' => 'Paiement partiel en suivi',
                'message' => 'La facture finale de Construction Horizon conserve un solde à recevoir.',
                'action_url' => '/invoices',
            ],
            [
                'type' => 'inventory',
                'severity' => 'warning',
                'title' => 'Consommables à commander',
                'message' => 'Deux consommables sont au seuil et le fini à plancher doit être réapprovisionné.',
                'action_url' => '/products',
            ],
            [
                'type' => 'planning',
                'severity' => 'info',
                'title' => 'Remplacement terrain confirmé',
                'message' => 'Samuel Roy couvre la prochaine rotation urgente de Gestion Loft 514.',
                'action_url' => '/planning',
            ],
            [
                'type' => 'task',
                'severity' => 'info',
                'title' => 'Preuves de passage complètes',
                'message' => 'Les interventions commerciales du soir disposent de leurs contrôles et photos.',
                'action_url' => '/tasks',
            ],
        ];

        foreach ($templates as $index => $template) {
            $context->owner->notify(new DemoActionNotification([
                ...$template,
                'scenario_key' => (string) $blueprint['key'],
            ]));
            $notification = $context->owner->notifications()->latest('created_at')->first();
            if ($notification) {
                $date = $context->referenceDate->subHours($index + 1);
                DB::table('notifications')->where('id', $notification->id)->update([
                    'created_at' => $date->utc(),
                    'updated_at' => $date->utc(),
                    'read_at' => $index === 5 ? $date->addMinutes(20)->utc() : null,
                ]);
            }
        }

        return count($templates);
    }

    /**
     * @return list<float>
     */
    private function splitMoney(float $total, int $parts): array
    {
        $parts = max(1, $parts);
        $totalCents = (int) round($total * 100);
        $base = intdiv($totalCents, $parts);
        $remainder = $totalCents % $parts;

        return collect(range(0, $parts - 1))
            ->map(fn (int $index): float => ($base + ($index < $remainder ? 1 : 0)) / 100)
            ->all();
    }

    private function historicalDate(DemoScenarioContext $context, int $index, int $total): CarbonImmutable
    {
        $start = $context->referenceDate->subMonths(11)->startOfMonth();
        $span = max(1, $start->diffInDays($context->referenceDate));
        $offset = $total <= 1
            ? 0
            : (int) floor(($index % $total) * $span / max(1, $total - 1));

        return $start->addDays(min($span, $offset))->setTime(8 + ($index % 10), ($index * 7) % 60);
    }

    private function serviceDurationMinutes(Product $service): int
    {
        foreach ((array) $service->tags as $tag) {
            if (is_string($tag) && str_starts_with($tag, 'duration:')) {
                return max(30, (int) Str::after($tag, 'duration:'));
            }
        }

        return 180;
    }

    private function serviceKey(Product $service): string
    {
        foreach ((array) $service->tags as $tag) {
            if (is_string($tag) && str_starts_with($tag, 'key:')) {
                return (string) Str::after($tag, 'key:');
            }
        }

        return Str::snake($service->name);
    }

    private function supplierForExpense(string $category): string
    {
        return match ($category) {
            'fleet', 'fuel' => 'Mobilité Rive-Sud',
            'inventory', 'supplies' => 'Hygiène Rive-Sud',
            'equipment', 'maintenance' => 'Équipements Pro-Clean',
            'insurance' => 'Assurances Laurentiennes',
            'software', 'utilities' => 'Services Affaires Québec',
            default => 'Fournisseur local approuvé',
        };
    }

    private function backdate(string $table, int|string $id, CarbonImmutable $date): void
    {
        DB::table($table)->where('id', $id)->update([
            'created_at' => $date->utc(),
            'updated_at' => $date->utc(),
        ]);
    }
}
