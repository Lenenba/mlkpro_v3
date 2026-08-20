<?php

namespace App\Services\Demo\Generators;

use App\Models\AvailabilityException;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ReservationResource;
use App\Models\ReservationSetting;
use App\Models\Role;
use App\Models\ServiceMaterial;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Services\Demo\DemoScenarioContext;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DemoTeamCatalogGenerator
{
    public function __construct(
        private readonly InventoryService $inventoryService,
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
        $catalog = $this->createCatalog($context, $blueprint, (int) ($targets['sales'] ?? 0));
        $this->attachSkillsAndMaterials($blueprint, $team, $catalog);
        $movementCount = $this->seedInventoryHistory(
            $context,
            $catalog['products'],
            (int) ($targets['inventory_movements'] ?? 0),
            (int) ($targets['sales'] ?? 0),
        );

        return [
            ...$team,
            ...$catalog,
            'inventory_movements' => $movementCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     */
    private function configureOwner(DemoScenarioContext $context, array $blueprint): void
    {
        $owner = $context->owner;
        $identity = (array) $blueprint['identity'];
        $address = (array) ($identity['address'] ?? []);
        $limits = (array) ($owner->company_limits ?? []);
        $features = (array) ($owner->company_features ?? []);

        $requiredModules = (array) config(
            'demo_scenarios.scenarios.'.(string) $blueprint['key'].'.required_modules',
            [],
        );
        foreach ($requiredModules as $module) {
            $moduleKey = trim((string) $module);
            if ($moduleKey !== '') {
                $features[$moduleKey] = true;
            }
        }

        $owner->forceFill([
            'name' => 'Maya Koné',
            'locale' => 'fr',
            'currency_code' => (string) ($identity['currency_code'] ?? 'CAD'),
            'phone_number' => $identity['phone'] ?? null,
            'company_name' => (string) $identity['name'],
            'company_description' => 'Salon montréalais inclusif spécialisé en coiffures texturées, coloration, soins et services barbier.',
            'company_country' => (string) ($address['country_code'] ?? 'CA'),
            'company_province' => (string) ($address['province'] ?? 'QC'),
            'company_city' => (string) ($address['city'] ?? 'Montréal'),
            'company_timezone' => $context->timezone,
            'company_type' => 'services',
            'company_sector' => 'salon',
            'company_team_size' => count((array) ($blueprint['employees'] ?? [])),
            'company_features' => $features,
            'company_limits' => array_replace($limits, [
                'customers' => 10000,
                'team_members' => 25,
                'products' => 1000,
                'invoices_monthly' => 10000,
                'storage_mb' => 5000,
            ]),
            'company_store_settings' => array_replace_recursive((array) ($owner->company_store_settings ?? []), [
                'tips' => [
                    'default_percent' => 15,
                    'quick_percents' => [10, 15, 18, 20],
                    'allocation_strategy' => 'primary',
                ],
            ]),
            'is_demo' => true,
            'is_demo_user' => true,
            'demo_type' => 'scenario:'.$blueprint['key'],
            'demo_role' => 'scenario_owner',
        ])->save();
        $this->backdateRecord('users', (int) $owner->id, $context->referenceDate->subMonths(18));

        ReservationSetting::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => null,
            'business_preset' => 'salon',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 60,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 24,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 10,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'team_member',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => true,
            'deposit_required' => true,
            'deposit_amount' => 30,
            'no_show_fee_enabled' => true,
            'no_show_fee_amount' => 35,
        ]);
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

        foreach ((array) $blueprint['employees'] as $index => $profile) {
            $key = (string) $profile['key'];
            $user = $index === 0
                ? $owner
                : User::query()->create([
                    'name' => (string) $profile['name'],
                    'email' => sprintf('%s-%d@studio-naya.example', Str::slug((string) $profile['name']), $context->workspace->id),
                    'password' => Hash::make('password'),
                    'role_id' => $employeeRole->id,
                    'locale' => 'fr',
                    'currency_code' => 'CAD',
                    'company_name' => $owner->company_name,
                    'company_type' => 'services',
                    'company_sector' => 'salon',
                    'company_timezone' => $context->timezone,
                    'email_verified_at' => $context->referenceDate,
                    'onboarding_completed_at' => $context->referenceDate,
                    'is_demo' => true,
                    'demo_type' => 'scenario:'.$blueprint['key'],
                    'is_demo_user' => true,
                    'demo_role' => 'scenario_staff',
                ]);

            $member = TeamMember::query()->create([
                'account_id' => $owner->id,
                'user_id' => $user->id,
                'role' => (string) $profile['role_key'],
                'title' => (string) $profile['title'],
                'phone' => sprintf('+1 514 555 %04d', 2000 + $index),
                'permissions' => array_values((array) $profile['permissions']),
                'planning_rules' => [
                    'scenario_key' => $key,
                    'demo_access_role' => $profile['demo_access_role'] ?? null,
                    'specialties' => array_values((array) $profile['specialties']),
                    'performance_profile' => (array) $profile['performance_profile'],
                    'break_minutes' => 30,
                    'min_hours_day' => 4,
                    'max_hours_day' => 10,
                    'max_hours_week' => 42,
                ],
                'is_active' => true,
            ]);
            $foundationDate = $context->referenceDate->subMonths(18)->addDays($index * 14);
            $this->backdateRecord('users', (int) $user->id, $foundationDate);
            $this->backdateRecord('team_members', (int) $member->id, $foundationDate);

            foreach ((array) $profile['schedule'] as $day => $hours) {
                WeeklyAvailability::query()->create([
                    'account_id' => $owner->id,
                    'team_member_id' => $member->id,
                    'day_of_week' => (int) $day,
                    'start_time' => (string) $hours['starts_at'].':00',
                    'end_time' => (string) $hours['ends_at'].':00',
                    'is_active' => true,
                ]);
            }

            $this->createAbsences($context, $member, (array) $profile['absence_templates'], $key);
            $this->createUpcomingShifts($context, $member, (array) $profile['schedule']);
            $this->createAttendanceHistory(
                $context,
                $member,
                $user,
                (array) $profile['schedule'],
                $index,
            );

            ReservationResource::query()->create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'name' => 'Fauteuil '.($index + 1),
                'type' => 'chair',
                'capacity' => 1,
                'is_active' => true,
                'metadata' => ['kind' => 'salon_chair', 'scenario_key' => $blueprint['key']],
            ]);

            $members->put($key, $member);
            $users->put($key, $user);
        }

        ReservationResource::query()->create([
            'account_id' => $owner->id,
            'name' => 'Bac de lavage 1',
            'type' => 'wash_basin',
            'capacity' => 1,
            'is_active' => true,
            'metadata' => ['kind' => 'wash_basin', 'scenario_key' => $blueprint['key']],
        ]);

        return ['team_members' => $members, 'staff_users' => $users];
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     */
    private function createAbsences(
        DemoScenarioContext $context,
        TeamMember $member,
        array $templates,
        string $memberKey,
    ): void {
        foreach ($templates as $index => $template) {
            $month = (int) ($template['preferred_month'] ?? 1);
            $year = $month > $context->referenceDate->month
                ? $context->referenceDate->year - 1
                : $context->referenceDate->year;
            $start = CarbonImmutable::create($year, $month, 8 + ($index * 5), 0, 0, 0, $context->timezone);

            foreach (range(0, max(0, (int) $template['duration_days'] - 1)) as $dayOffset) {
                $date = $start->addDays($dayOffset);
                AvailabilityException::query()->create([
                    'account_id' => $context->owner->id,
                    'team_member_id' => $member->id,
                    'date' => $date->toDateString(),
                    'start_time' => '00:00:00',
                    'end_time' => '23:59:59',
                    'type' => AvailabilityException::TYPE_CLOSED,
                    'reason' => Str::headline((string) $template['kind']).' · '.$memberKey,
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{starts_at:string, ends_at:string}>  $schedule
     */
    private function createUpcomingShifts(
        DemoScenarioContext $context,
        TeamMember $member,
        array $schedule,
    ): void {
        $date = $context->referenceDate;
        $created = 0;

        while ($created < 10) {
            $date = $date->addDay();
            $hours = $schedule[$date->dayOfWeekIso] ?? null;
            if (! is_array($hours)) {
                continue;
            }

            TeamMemberShift::query()->create([
                'account_id' => $context->owner->id,
                'team_member_id' => $member->id,
                'created_by_user_id' => $context->owner->id,
                'approved_by_user_id' => $context->owner->id,
                'approved_at' => $context->referenceDate->subDays(2),
                'kind' => 'shift',
                'status' => 'approved',
                'title' => 'Quart Studio Naya',
                'shift_date' => $date->toDateString(),
                'start_time' => $hours['starts_at'].':00',
                'end_time' => $hours['ends_at'].':00',
                'break_minutes' => 30,
                'recurrence_group_id' => 'studio-naya-'.$member->id,
            ]);
            $created++;
        }
    }

    /**
     * Seed a compact attendance history plus one current presence state.
     *
     * Keeping this history bounded makes the scenario useful without tying
     * attendance volume to the much larger reservation volume.
     *
     * @param  array<int, array{starts_at:string, ends_at:string}>  $schedule
     */
    private function createAttendanceHistory(
        DemoScenarioContext $context,
        TeamMember $member,
        User $user,
        array $schedule,
        int $memberIndex,
    ): void {
        $date = $context->referenceDate;
        $created = 0;
        $daysScanned = 0;

        while ($created < 12 && $daysScanned < 90) {
            $date = $date->subDay();
            $daysScanned++;
            $hours = $schedule[$date->dayOfWeekIso] ?? null;
            if (! is_array($hours)) {
                continue;
            }

            $clockIn = $date
                ->setTimeFromTimeString((string) $hours['starts_at'])
                ->addMinutes(($memberIndex * 3 + $created) % 9)
                ->utc();
            $clockOut = $date
                ->setTimeFromTimeString((string) $hours['ends_at'])
                ->subMinutes(($memberIndex + $created) % 11)
                ->utc();

            TeamMemberAttendance::query()->create([
                'account_id' => $context->owner->id,
                'user_id' => $user->id,
                'team_member_id' => $member->id,
                'clock_in_at' => $clockIn,
                'clock_out_at' => $clockOut,
                'method' => 'demo',
                'clock_out_method' => 'demo',
                'current_status' => TeamMemberAttendance::STATUS_OFFLINE,
            ]);
            $created++;
        }

        $todayHours = $schedule[$context->referenceDate->dayOfWeekIso] ?? [
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ];
        $currentStatuses = [
            TeamMemberAttendance::STATUS_AVAILABLE,
            TeamMemberAttendance::STATUS_BUSY,
            TeamMemberAttendance::STATUS_BREAK,
        ];

        TeamMemberAttendance::query()->create([
            'account_id' => $context->owner->id,
            'user_id' => $user->id,
            'team_member_id' => $member->id,
            'clock_in_at' => $context->referenceDate
                ->setTimeFromTimeString((string) $todayHours['starts_at'])
                ->addMinutes($memberIndex * 4)
                ->utc(),
            'clock_out_at' => null,
            'method' => 'demo',
            'clock_out_method' => null,
            'current_status' => $currentStatuses[$memberIndex % count($currentStatuses)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{services: Collection<string, Product>, products: Collection<string, Product>}
     */
    private function createCatalog(
        DemoScenarioContext $context,
        array $blueprint,
        int $saleTarget,
    ): array {
        $owner = $context->owner;
        $categories = collect();

        foreach ((array) $blueprint['service_categories'] as $definition) {
            $categories->put((string) $definition['key'], ProductCategory::query()->create([
                'name' => (string) $definition['name'],
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]));
        }

        foreach (collect((array) $blueprint['products'])->pluck('category_key')->unique() as $key) {
            if ($categories->has($key)) {
                continue;
            }

            $categories->put((string) $key, ProductCategory::query()->create([
                'name' => Str::headline((string) $key),
                'user_id' => $owner->id,
                'created_by_user_id' => $owner->id,
            ]));
        }

        $services = collect();
        foreach ((array) $blueprint['services'] as $definition) {
            $tags = [
                ...(array) $definition['tags'],
                'scenario:studio_naya',
                'key:'.$definition['key'],
                'duration:'.$definition['duration_minutes'],
                'preparation:'.$definition['preparation_minutes'],
                'cleanup:'.$definition['cleanup_minutes'],
                'buffer-before:'.$definition['buffer_before_minutes'],
                'buffer-after:'.$definition['buffer_after_minutes'],
                'calendar-color:'.$definition['calendar_color'],
            ];

            $service = Product::query()->create([
                'name' => (string) $definition['name'],
                'description' => (string) $definition['description'],
                'tags' => array_values($tags),
                'category_id' => $categories->get($definition['category_key'])?->id,
                'stock' => 0,
                'minimum_stock' => 0,
                'price' => (float) $definition['price'],
                'currency_code' => 'CAD',
                'unit' => 'service',
                'cost_price' => round((float) $definition['price'] * 0.32, 2),
                'margin_percent' => 68,
                'tax_rate' => 14.975,
                'is_active' => (bool) $definition['active'],
                'user_id' => $owner->id,
                'item_type' => Product::ITEM_TYPE_SERVICE,
                'tracking_type' => 'none',
            ]);
            $this->backdateCatalogItem($service, $context->referenceDate->subMonths(18));
            $services->put((string) $definition['key'], $service);
        }

        $supplierMap = collect((array) $blueprint['suppliers'])->keyBy('key');
        $retailProducts = collect((array) $blueprint['products'])->where('retail', true)->values();
        $saleAllocations = array_fill(0, max(1, $retailProducts->count()), 0);
        for ($index = 0; $index < $saleTarget; $index++) {
            $saleAllocations[$index % count($saleAllocations)]++;
        }

        $products = collect();
        $retailIndex = 0;
        foreach ((array) $blueprint['products'] as $definition) {
            $supplier = $supplierMap->get($definition['supplier_key'], []);
            $plannedSales = (bool) $definition['retail'] ? $saleAllocations[$retailIndex++] : 0;
            $product = Product::query()->create([
                'name' => (string) $definition['name'],
                'description' => 'Produit Studio Naya · '.Str::headline((string) $definition['category_key']),
                'tags' => [
                    'scenario:studio_naya',
                    'key:'.$definition['key'],
                    (bool) $definition['retail'] ? 'retail' : 'professional',
                ],
                'category_id' => $categories->get($definition['category_key'])?->id,
                'stock' => 0,
                'minimum_stock' => (int) $definition['reorder_threshold'],
                'price' => (float) $definition['price'],
                'currency_code' => 'CAD',
                'sku' => 'NAYA-'.Str::upper(Str::replace('_', '-', (string) $definition['key'])),
                'unit' => (string) $definition['unit'],
                'supplier_name' => $supplier['name'] ?? null,
                'supplier_email' => $supplier['email'] ?? null,
                'cost_price' => (float) $definition['cost'],
                'margin_percent' => (float) $definition['price'] > 0
                    ? round((((float) $definition['price'] - (float) $definition['cost']) / (float) $definition['price']) * 100, 2)
                    : 0,
                'tax_rate' => 14.975,
                'is_active' => (bool) $definition['active'],
                'user_id' => $owner->id,
                'item_type' => Product::ITEM_TYPE_PRODUCT,
                'tracking_type' => 'stock',
            ]);
            $this->backdateCatalogItem($product, $context->referenceDate->subMonths(18));
            $product->setAttribute('scenario_target_stock', (int) $definition['stock_on_hand']);
            $product->setAttribute('scenario_initial_stock', (int) $definition['stock_on_hand'] + $plannedSales);
            $product->setAttribute('scenario_retail', (bool) $definition['retail']);
            $products->put((string) $definition['key'], $product);
        }

        return ['services' => $services, 'products' => $products];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array{team_members: Collection<string, TeamMember>}  $team
     * @param  array{services: Collection<string, Product>, products: Collection<string, Product>}  $catalog
     */
    private function attachSkillsAndMaterials(array $blueprint, array $team, array $catalog): void
    {
        foreach ((array) $blueprint['employee_service_matrix'] as $memberKey => $matrix) {
            $member = $team['team_members']->get($memberKey);
            if (! $member) {
                continue;
            }

            $rules = (array) ($member->planning_rules ?? []);
            $rules['bookable_service_keys'] = array_values((array) $matrix['bookable_service_keys']);
            $rules['bookable_service_ids'] = $catalog['services']
                ->only((array) $matrix['bookable_service_keys'])
                ->pluck('id')
                ->values()
                ->all();
            $rules['assist_only_service_keys'] = array_values((array) $matrix['assist_only_service_keys']);
            $member->forceFill(['planning_rules' => $rules])->save();
        }

        $serviceDefinitions = collect((array) $blueprint['services'])->keyBy('key');
        foreach ($catalog['services'] as $serviceKey => $service) {
            $consumables = (array) data_get($serviceDefinitions, $serviceKey.'.metadata.consumables', []);
            foreach ($consumables as $index => $consumable) {
                $product = $catalog['products']->get($consumable['product_key']);
                if (! $product) {
                    continue;
                }

                ServiceMaterial::query()->create([
                    'service_id' => $service->id,
                    'product_id' => $product->id,
                    'label' => $product->name,
                    'description' => 'Consommable utilisé pendant le service.',
                    'unit' => $product->unit,
                    'quantity' => (float) $consumable['quantity'],
                    'unit_price' => (float) $product->cost_price,
                    'billable' => false,
                    'sort_order' => $index,
                ]);
            }
        }
    }

    /**
     * @param  Collection<string, Product>  $products
     */
    private function seedInventoryHistory(
        DemoScenarioContext $context,
        Collection $products,
        int $movementTarget,
        int $saleTarget,
    ): int {
        if ($products->isEmpty() || $movementTarget <= 0) {
            return 0;
        }

        $baselineTarget = max($products->count(), $movementTarget - $saleTarget);
        $created = 0;
        $historyStart = $context->referenceDate->subMonths(18);

        foreach ($products as $index => $product) {
            $quantity = (int) $product->getAttribute('scenario_initial_stock');
            $movement = $this->inventoryService->adjust($product, $quantity, 'in', [
                'account_id' => $context->owner->id,
                'actor_id' => $context->owner->id,
                'reason' => 'scenario_opening_stock',
                'unit_cost' => $product->cost_price,
                'note' => 'Réception initiale Studio Naya.',
                'meta' => ['scenario_key' => 'studio_naya_coiffure'],
            ]);
            $this->backdateMovement($movement->id, $historyStart->addDays($index));
            $created++;
        }

        $movable = $products->filter(
            fn (Product $product): bool => (int) $product->getAttribute('scenario_initial_stock') > 1,
        )->values();

        while ($created < $baselineTarget) {
            $product = $movable[$created % $movable->count()];
            $remaining = $baselineTarget - $created;
            $offset = $created - $products->count();
            $type = $remaining === 1 && $offset % 2 === 0
                ? 'adjust'
                : ($offset % 2 === 0 ? 'in' : 'out');
            $quantity = $type === 'adjust' ? 0 : 1;
            $movement = $this->inventoryService->adjust($product, $quantity, $type, [
                'account_id' => $context->owner->id,
                'actor_id' => $context->owner->id,
                'reason' => match ($type) {
                    'in' => 'supplier_delivery',
                    'out' => 'salon_consumption',
                    default => 'inventory_cycle_count',
                },
                'unit_cost' => $product->cost_price,
                'note' => match ($type) {
                    'in' => 'Réception fournisseur planifiée.',
                    'out' => 'Consommation salon enregistrée.',
                    default => 'Contrôle de stock sans écart.',
                },
                'meta' => ['scenario_key' => 'studio_naya_coiffure'],
            ]);
            $this->backdateMovement(
                $movement->id,
                $historyStart->addDays($created % 535)->addMinutes($created % 480),
            );
            $created++;
        }

        return $created;
    }

    private function backdateMovement(int $movementId, CarbonImmutable $date): void
    {
        DB::table('product_stock_movements')
            ->where('id', $movementId)
            ->update(['created_at' => $date->utc(), 'updated_at' => $date->utc()]);
    }

    private function backdateCatalogItem(Product $product, CarbonImmutable $date): void
    {
        DB::table('products')->where('id', $product->id)->update([
            'created_at' => $date->utc(),
            'updated_at' => $date->utc(),
        ]);
    }

    private function backdateRecord(string $table, int $id, CarbonImmutable $date): void
    {
        DB::table($table)->where('id', $id)->update([
            'created_at' => $date->utc(),
            'updated_at' => $date->utc(),
        ]);
    }
}
