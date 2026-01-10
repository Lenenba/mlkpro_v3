<?php

namespace App\Services\Demo;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DemoTourStep;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeedService
{
    public function seed(User $account, string $type): void
    {
        if (!config('demo.enabled')) {
            return;
        }

        $type = $this->normalizeType($type);

        if ($type === DemoAccountService::TYPE_GUIDED) {
            $this->ensureTourSteps();
            $this->seedGuidedDemo($account);
            return;
        }

        if ($type === DemoAccountService::TYPE_PRODUCT) {
            $this->seedProductDemo($account);
            return;
        }

        $this->seedServiceDemo($account);
    }

    private function seedServiceDemo(User $account): void
    {
        $accountId = $account->accountOwnerId();
        $domain = config('demo.accounts_email_domain', 'example.test');

        $customers = $this->seedCustomers(
            $accountId,
            'service',
            $domain,
            [
                ['first' => 'Liam', 'last' => 'Carter', 'company' => 'Blue Harbor Condos'],
                ['first' => 'Ava', 'last' => 'Mitchell', 'company' => 'Brightview HOA'],
                ['first' => 'Noah', 'last' => 'Gomez', 'company' => 'Summit Property Group'],
                ['first' => 'Mia', 'last' => 'Howard', 'company' => 'Lakeview Estates'],
                ['first' => 'Ethan', 'last' => 'Reed', 'company' => 'Northline Facilities'],
                ['first' => 'Olivia', 'last' => 'Wright', 'company' => 'Evergreen Residences'],
                ['first' => 'Lucas', 'last' => 'King', 'company' => 'Metroline Commercial'],
                ['first' => 'Amelia', 'last' => 'Scott', 'company' => 'Maple Grove Rentals'],
                ['first' => 'Mason', 'last' => 'Baker', 'company' => 'Silvercrest Offices'],
                ['first' => 'Sophia', 'last' => 'Turner', 'company' => 'Riverside Apartments'],
            ]
        );

        $serviceCategory = ProductCategory::resolveForAccount($accountId, $accountId, 'Services');
        $services = $this->seedCatalog($accountId, $serviceCategory, Product::ITEM_TYPE_SERVICE, [
            ['name' => 'Deep Cleaning', 'price' => 180, 'unit' => 'visit'],
            ['name' => 'HVAC Maintenance', 'price' => 240, 'unit' => 'service'],
            ['name' => 'Lawn Care', 'price' => 120, 'unit' => 'visit'],
            ['name' => 'Emergency Repair', 'price' => 320, 'unit' => 'job'],
            ['name' => 'Monthly Maintenance', 'price' => 260, 'unit' => 'month'],
        ]);

        if (Quote::query()->where('user_id', $accountId)->exists()) {
            return;
        }

        $quotes = [];
        $quotes[] = $this->createQuote($accountId, $customers[0], [
            $this->buildLine($services[0], 1),
            $this->buildLine($services[2], 2),
        ], [
            'job_title' => 'Lobby refresh and deep clean',
            'status' => 'sent',
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[1], [
            $this->buildLine($services[1], 1),
        ], [
            'job_title' => 'Quarterly HVAC checkup',
            'status' => 'draft',
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[2], [
            $this->buildLine($services[3], 1),
            $this->buildLine($services[0], 1),
        ], [
            'job_title' => 'Emergency call-out',
            'status' => 'accepted',
            'accepted_at' => now()->subDays(5),
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[3], [
            $this->buildLine($services[4], 1),
        ], [
            'job_title' => 'Monthly maintenance',
            'status' => 'accepted',
            'accepted_at' => now()->subDays(3),
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[4], [
            $this->buildLine($services[2], 1),
            $this->buildLine($services[0], 1),
        ], [
            'job_title' => 'Seasonal landscaping',
            'status' => 'accepted',
            'accepted_at' => now()->subDays(1),
        ]);

        $works = [];
        $works[] = $this->createWorkFromQuote($accountId, $quotes[2], [
            'status' => Work::STATUS_SCHEDULED,
            'start_date' => now()->addDays(2)->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);
        $works[] = $this->createWorkFromQuote($accountId, $quotes[3], [
            'status' => Work::STATUS_IN_PROGRESS,
            'start_date' => now()->addDays(1)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '15:00:00',
        ]);
        $works[] = $this->createWorkFromQuote($accountId, $quotes[4], [
            'status' => Work::STATUS_TECH_COMPLETE,
            'start_date' => now()->subDays(1)->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '11:30:00',
        ]);

        foreach ($works as $index => $work) {
            $this->seedTasks($accountId, $work, null, [
                [
                    'title' => 'Site prep',
                    'status' => $index === 2 ? 'done' : 'in_progress',
                    'due_date' => now()->addDays(1 + $index)->toDateString(),
                ],
                [
                    'title' => 'Client walkthrough',
                    'status' => 'todo',
                    'due_date' => now()->addDays(2 + $index)->toDateString(),
                ],
            ]);
        }

        $draftInvoice = $this->createInvoiceFromWork($accountId, $works[0], 'draft');
        $paidInvoice = $this->createInvoiceFromWork($accountId, $works[1], 'paid');
        $this->recordPayment($accountId, $paidInvoice, 0.0);

        ActivityLog::record($account, $quotes[2], 'created', [
            'status' => $quotes[2]->status,
            'total' => $quotes[2]->total,
        ], 'Quote accepted');
        ActivityLog::record($account, $works[0], 'created', [
            'status' => $works[0]->status,
            'total' => $works[0]->total,
        ], 'Job scheduled');
        ActivityLog::record($account, $draftInvoice, 'created', [
            'status' => $draftInvoice->status,
            'total' => $draftInvoice->total,
        ], 'Invoice drafted');
    }

    private function seedProductDemo(User $account): void
    {
        $accountId = $account->accountOwnerId();
        $domain = config('demo.accounts_email_domain', 'example.test');

        $customers = $this->seedCustomers(
            $accountId,
            'product',
            $domain,
            [
                ['first' => 'Emma', 'last' => 'Lewis', 'company' => 'Northwind Retail'],
                ['first' => 'James', 'last' => 'Campbell', 'company' => 'Cedar Supply'],
                ['first' => 'Charlotte', 'last' => 'Moore', 'company' => 'Harbor Mart'],
                ['first' => 'Henry', 'last' => 'Collins', 'company' => 'Summit Hardware'],
                ['first' => 'Isabella', 'last' => 'Parker', 'company' => 'District Depot'],
                ['first' => 'Benjamin', 'last' => 'Rogers', 'company' => 'Canyon Traders'],
            ]
        );

        $categories = [
            ProductCategory::resolveForAccount($accountId, $accountId, 'Electronics'),
            ProductCategory::resolveForAccount($accountId, $accountId, 'Consumables'),
            ProductCategory::resolveForAccount($accountId, $accountId, 'Parts'),
        ];

        $products = $this->seedCatalog($accountId, $categories[0], Product::ITEM_TYPE_PRODUCT, [
            ['name' => 'Smart Panel Sensor', 'price' => 65, 'stock' => 30, 'minimum_stock' => 10, 'sku' => 'EL-4021'],
            ['name' => 'Wireless Gateway', 'price' => 180, 'stock' => 12, 'minimum_stock' => 5, 'sku' => 'EL-4392'],
            ['name' => 'Mobile Scanner', 'price' => 240, 'stock' => 8, 'minimum_stock' => 4, 'sku' => 'EL-3003'],
        ]);

        $products = array_merge($products, $this->seedCatalog($accountId, $categories[1], Product::ITEM_TYPE_PRODUCT, [
            ['name' => 'Premium Packaging Pack', 'price' => 18, 'stock' => 120, 'minimum_stock' => 40, 'sku' => 'CO-1022'],
            ['name' => 'Cleaning Solution Kit', 'price' => 22, 'stock' => 80, 'minimum_stock' => 25, 'sku' => 'CO-1140'],
            ['name' => 'Protective Gloves (Box)', 'price' => 14, 'stock' => 200, 'minimum_stock' => 50, 'sku' => 'CO-1180'],
        ]));

        $products = array_merge($products, $this->seedCatalog($accountId, $categories[2], Product::ITEM_TYPE_PRODUCT, [
            ['name' => 'Replacement Filter Set', 'price' => 48, 'stock' => 60, 'minimum_stock' => 15, 'sku' => 'PT-5501'],
            ['name' => 'Valve Assembly', 'price' => 72, 'stock' => 20, 'minimum_stock' => 6, 'sku' => 'PT-6120'],
            ['name' => 'Control Board', 'price' => 110, 'stock' => 15, 'minimum_stock' => 4, 'sku' => 'PT-7130'],
            ['name' => 'Compact Motor', 'price' => 140, 'stock' => 9, 'minimum_stock' => 3, 'sku' => 'PT-8200'],
        ]));

        $products = array_merge($products, $this->seedCatalog($accountId, $categories[1], Product::ITEM_TYPE_PRODUCT, [
            ['name' => 'Sealant Tube', 'price' => 9, 'stock' => 150, 'minimum_stock' => 60, 'sku' => 'CO-1290'],
            ['name' => 'Packaging Tape Roll', 'price' => 6, 'stock' => 180, 'minimum_stock' => 80, 'sku' => 'CO-1302'],
            ['name' => 'Cable Ties Pack', 'price' => 4, 'stock' => 260, 'minimum_stock' => 100, 'sku' => 'CO-1322'],
            ['name' => 'Mounting Brackets', 'price' => 12, 'stock' => 70, 'minimum_stock' => 20, 'sku' => 'PT-9050'],
            ['name' => 'Battery Pack', 'price' => 35, 'stock' => 40, 'minimum_stock' => 12, 'sku' => 'EL-5115'],
            ['name' => 'Portable Tool Kit', 'price' => 95, 'stock' => 14, 'minimum_stock' => 4, 'sku' => 'PT-7711'],
            ['name' => 'Inspection Camera', 'price' => 210, 'stock' => 6, 'minimum_stock' => 2, 'sku' => 'EL-6777'],
            ['name' => 'Thermal Printer', 'price' => 160, 'stock' => 10, 'minimum_stock' => 3, 'sku' => 'EL-6950'],
            ['name' => 'Safety Goggles', 'price' => 11, 'stock' => 90, 'minimum_stock' => 30, 'sku' => 'CO-1411'],
            ['name' => 'Portable Light', 'price' => 28, 'stock' => 45, 'minimum_stock' => 15, 'sku' => 'EL-7202'],
            ['name' => 'Spare Charging Cable', 'price' => 12, 'stock' => 70, 'minimum_stock' => 20, 'sku' => 'EL-7300'],
        ]));

        if (Quote::query()->where('user_id', $accountId)->exists()) {
            return;
        }

        $quotes = [];
        $quotes[] = $this->createQuote($accountId, $customers[0], [
            $this->buildLine($products[0], 5),
            $this->buildLine($products[3], 10),
        ], [
            'job_title' => 'Retail sensor restock',
            'status' => 'sent',
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[1], [
            $this->buildLine($products[2], 2),
            $this->buildLine($products[6], 4),
        ], [
            'job_title' => 'Seasonal parts order',
            'status' => 'accepted',
            'accepted_at' => now()->subDays(2),
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[2], [
            $this->buildLine($products[4], 8),
            $this->buildLine($products[9], 3),
        ], [
            'job_title' => 'Warehouse consumables',
            'status' => 'draft',
        ]);
        $quotes[] = $this->createQuote($accountId, $customers[3], [
            $this->buildLine($products[1], 1),
            $this->buildLine($products[8], 6),
        ], [
            'job_title' => 'Hardware order',
            'status' => 'accepted',
            'accepted_at' => now()->subDay(),
        ]);

        $works = [];
        $works[] = $this->createWorkFromQuote($accountId, $quotes[1], [
            'status' => Work::STATUS_SCHEDULED,
            'start_date' => now()->addDays(3)->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '14:00:00',
        ]);
        $works[] = $this->createWorkFromQuote($accountId, $quotes[3], [
            'status' => Work::STATUS_IN_PROGRESS,
            'start_date' => now()->addDays(1)->toDateString(),
            'start_time' => '09:30:00',
            'end_time' => '12:30:00',
        ]);

        $invoiceA = $this->createInvoiceFromWork($accountId, $works[0], 'sent');
        $invoiceB = $this->createInvoiceFromWork($accountId, $works[1], 'paid');
        $this->recordPayment($accountId, $invoiceB, 0.0);

        ActivityLog::record($account, $quotes[1], 'created', [
            'status' => $quotes[1]->status,
            'total' => $quotes[1]->total,
        ], 'Quote accepted');
        ActivityLog::record($account, $invoiceB, 'created', [
            'status' => $invoiceB->status,
            'total' => $invoiceB->total,
        ], 'Invoice paid');
    }

    private function seedGuidedDemo(User $account): void
    {
        $accountId = $account->accountOwnerId();
        $domain = config('demo.accounts_email_domain', 'example.test');

        $guidedCustomer = Customer::updateOrCreate(
            [
                'user_id' => $accountId,
                'email' => $this->guidedCustomerEmail($accountId, $domain),
            ],
            [
                'first_name' => 'Demo',
                'last_name' => 'Customer',
                'company_name' => 'Guided Demo Client',
                'phone' => '555-0101',
                'portal_access' => true,
                'billing_same_as_physical' => true,
                'salutation' => 'Mrs',
            ]
        );

        Property::updateOrCreate(
            [
                'customer_id' => $guidedCustomer->id,
                'type' => 'physical',
                'is_default' => true,
            ],
            [
                'country' => 'US',
                'street1' => '320 Demo Street',
                'street2' => null,
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '73301',
            ]
        );

        $serviceCategory = ProductCategory::resolveForAccount($accountId, $accountId, 'Services');
        $this->seedCatalog($accountId, $serviceCategory, Product::ITEM_TYPE_SERVICE, [
            ['name' => 'Starter Inspection', 'price' => 95, 'unit' => 'visit'],
            ['name' => 'Preventive Tune-up', 'price' => 165, 'unit' => 'service'],
            ['name' => 'On-site Support', 'price' => 145, 'unit' => 'hour'],
        ]);

        $this->seedTeamMember($accountId, $domain);
    }

    private function seedCustomers(int $accountId, string $prefix, string $domain, array $customers): array
    {
        $results = [];
        $addresses = $this->demoAddresses();

        foreach ($customers as $index => $data) {
            $email = "{$prefix}-customer-" . ($index + 1) . "-{$accountId}@{$domain}";
            $customer = Customer::updateOrCreate(
                [
                    'user_id' => $accountId,
                    'email' => $email,
                ],
                [
                    'first_name' => $data['first'],
                    'last_name' => $data['last'],
                    'company_name' => $data['company'],
                    'phone' => '555-01' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'portal_access' => (bool) ($index % 2 === 0),
                    'billing_same_as_physical' => true,
                    'salutation' => 'Mr',
                ]
            );

            $address = $addresses[$index % count($addresses)];
            Property::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'type' => 'physical',
                    'is_default' => true,
                ],
                $address
            );

            $results[] = $customer;
        }

        return $results;
    }

    private function seedCatalog(int $accountId, ProductCategory $category, string $itemType, array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $results[] = Product::updateOrCreate(
                [
                    'user_id' => $accountId,
                    'name' => $item['name'],
                    'item_type' => $itemType,
                ],
                [
                    'description' => $item['description'] ?? $item['name'],
                    'category_id' => $category->id,
                    'price' => $item['price'] ?? 0,
                    'stock' => $item['stock'] ?? 0,
                    'minimum_stock' => $item['minimum_stock'] ?? 0,
                    'sku' => $item['sku'] ?? strtoupper(Str::random(6)),
                    'unit' => $item['unit'] ?? null,
                    'is_active' => true,
                ]
            );
        }

        return $results;
    }

    private function buildLine(Product $product, int $quantity): array
    {
        $price = (float) ($product->price ?? 0);
        return [
            'product' => $product,
            'quantity' => $quantity,
            'price' => $price,
            'total' => round($price * $quantity, 2),
            'description' => $product->description,
        ];
    }

    private function createQuote(int $accountId, Customer $customer, array $lines, array $overrides = []): Quote
    {
        $subtotal = collect($lines)->sum('total');
        $total = round($subtotal, 2);

        $quote = Quote::create([
            'user_id' => $accountId,
            'customer_id' => $customer->id,
            'property_id' => $customer->properties()->value('id'),
            'job_title' => $overrides['job_title'] ?? 'New Quote',
            'status' => $overrides['status'] ?? 'draft',
            'subtotal' => $subtotal,
            'total' => $total,
            'notes' => $overrides['notes'] ?? null,
            'messages' => $overrides['messages'] ?? null,
            'initial_deposit' => $overrides['initial_deposit'] ?? 0,
            'accepted_at' => $overrides['accepted_at'] ?? null,
            'signed_at' => $overrides['signed_at'] ?? null,
        ]);

        $pivotData = [];
        foreach ($lines as $line) {
            $pivotData[$line['product']->id] = [
                'quantity' => $line['quantity'],
                'price' => $line['price'],
                'description' => $line['description'] ?? null,
                'total' => $line['total'],
            ];
        }
        $quote->products()->sync($pivotData);

        return $quote;
    }

    private function createWorkFromQuote(int $accountId, Quote $quote, array $overrides = []): Work
    {
        $work = Work::create([
            'user_id' => $accountId,
            'customer_id' => $quote->customer_id,
            'quote_id' => $quote->id,
            'job_title' => $quote->job_title,
            'instructions' => $quote->notes ?: ($quote->messages ?: 'Service visit'),
            'status' => $overrides['status'] ?? Work::STATUS_TO_SCHEDULE,
            'start_date' => $overrides['start_date'] ?? null,
            'end_date' => $overrides['end_date'] ?? null,
            'start_time' => $overrides['start_time'] ?? null,
            'end_time' => $overrides['end_time'] ?? null,
            'subtotal' => $quote->subtotal,
            'total' => $quote->total,
        ]);

        $quote->loadMissing('products');
        $pivotData = $quote->products->mapWithKeys(function (Product $product) use ($quote) {
            return [
                $product->id => [
                    'quote_id' => $quote->id,
                    'quantity' => (int) $product->pivot->quantity,
                    'price' => (float) $product->pivot->price,
                    'description' => $product->pivot->description,
                    'total' => (float) $product->pivot->total,
                ],
            ];
        });
        $work->products()->sync($pivotData->toArray());

        $quote->update([
            'work_id' => $work->id,
            'accepted_at' => $quote->accepted_at ?? now(),
            'signed_at' => $quote->signed_at ?? now(),
        ]);

        return $work;
    }

    private function seedTasks(int $accountId, Work $work, ?TeamMember $assignee, array $tasks): void
    {
        foreach ($tasks as $taskData) {
            Task::create([
                'account_id' => $accountId,
                'created_by_user_id' => $accountId,
                'assigned_team_member_id' => $assignee?->id,
                'customer_id' => $work->customer_id,
                'work_id' => $work->id,
                'title' => $taskData['title'],
                'description' => $taskData['description'] ?? null,
                'status' => $taskData['status'] ?? 'todo',
                'billable' => true,
                'due_date' => $taskData['due_date'] ?? null,
                'start_time' => $taskData['start_time'] ?? null,
                'end_time' => $taskData['end_time'] ?? null,
                'completed_at' => ($taskData['status'] ?? '') === 'done' ? now() : null,
            ]);
        }
    }

    private function createInvoiceFromWork(int $accountId, Work $work, string $status): Invoice
    {
        $work->loadMissing('products');
        $lineItems = $work->products->map(function (Product $product) use ($work) {
            return [
                'work_id' => $work->id,
                'title' => $product->name ?? 'Line item',
                'description' => $product->pivot?->description ?: $product->description,
                'scheduled_date' => $work->start_date,
                'start_time' => $work->start_time,
                'end_time' => $work->end_time,
                'quantity' => (float) ($product->pivot?->quantity ?? 1),
                'unit_price' => (float) ($product->pivot?->price ?? $product->price ?? 0),
                'total' => (float) ($product->pivot?->total ?? 0),
                'meta' => [
                    'source' => 'work',
                    'product_id' => $product->id,
                ],
            ];
        });

        $total = round($lineItems->sum('total'), 2);
        $invoice = Invoice::create([
            'user_id' => $accountId,
            'customer_id' => $work->customer_id,
            'work_id' => $work->id,
            'status' => $status,
            'total' => $total,
        ]);

        if ($lineItems->isNotEmpty()) {
            $invoice->items()->createMany($lineItems->all());
        }

        return $invoice;
    }

    private function recordPayment(int $accountId, Invoice $invoice, float $amountOverride = 0.0): void
    {
        $amount = $amountOverride > 0 ? $amountOverride : (float) $invoice->total;
        if ($amount <= 0) {
            return;
        }

        Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'user_id' => $accountId,
            'amount' => $amount,
            'method' => 'card',
            'status' => 'completed',
            'reference' => Str::upper(Str::random(8)),
            'paid_at' => now(),
        ]);

        $invoice->refreshPaymentStatus();
    }

    private function seedTeamMember(int $accountId, string $domain): ?TeamMember
    {
        $roleId = Role::firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role']
        )->id;

        $email = "guided-demo-tech-{$accountId}@{$domain}";
        $employee = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Guided Demo Tech',
                'role_id' => $roleId,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $employee->forceFill([
            'is_demo' => true,
            'demo_type' => DemoAccountService::TYPE_GUIDED,
            'is_demo_user' => true,
            'demo_role' => 'guided_demo',
        ])->save();

        return TeamMember::firstOrCreate(
            [
                'account_id' => $accountId,
                'user_id' => $employee->id,
            ],
            [
                'role' => 'technician',
                'title' => 'Field Technician',
                'phone' => '555-0199',
                'permissions' => ['jobs', 'tasks'],
                'is_active' => true,
            ]
        );
    }

    private function ensureTourSteps(): void
    {
        $steps = $this->buildTourSteps();

        foreach ($steps as $step) {
            DemoTourStep::updateOrCreate(['key' => $step['key']], $step);
        }
    }

    private function buildTourSteps(): array
    {
        $steps = [];
        $steps[] = $this->step(
            key: 'dashboard_overview',
            title: 'Dashboard overview',
            description: 'Start with the KPI snapshot and overall activity.',
            routeName: 'dashboard',
            selector: '[data-testid="demo-dashboard-overview"]',
            placement: 'bottom',
            order: 1,
            group: 'Dashboard',
            completion: ['type' => 'view']
        );
        $steps[] = $this->step(
            key: 'dashboard_notifications',
            title: 'Notifications',
            description: 'Use the bell to review alerts and updates.',
            routeName: 'dashboard',
            selector: '[data-testid="demo-notifications-bell"]',
            placement: 'bottom',
            order: 2,
            group: 'Dashboard',
            completion: ['type' => 'manual']
        );
        $steps[] = $this->step(
            key: 'customer_create',
            title: 'Create a customer',
            description: 'Add your first customer record to start building quotes.',
            routeName: 'customer.create',
            selector: '[data-testid="demo-customer-save"]',
            placement: 'right',
            order: 3,
            group: 'Customers',
            completion: ['type' => 'event', 'event' => 'demo:customer_created']
        );
        $steps[] = $this->step(
            key: 'quote_line_items',
            title: 'Add quote line items',
            description: 'Add services or products to build the quote total.',
            routeName: 'customer.quote.create',
            selector: '[data-testid="demo-quote-line-items"]',
            placement: 'top',
            order: 4,
            group: 'Quotes',
            completion: ['type' => 'manual'],
            routeParams: ['customer' => 'demo_customer']
        );
        $steps[] = $this->step(
            key: 'quote_save',
            title: 'Save the quote',
            description: 'Create the quote and return to your customer list.',
            routeName: 'customer.quote.create',
            selector: '[data-testid="demo-quote-save"]',
            placement: 'bottom',
            order: 5,
            group: 'Quotes',
            completion: ['type' => 'event', 'event' => 'demo:quote_created'],
            routeParams: ['customer' => 'demo_customer']
        );
        $steps[] = $this->step(
            key: 'quote_send',
            title: 'Send the quote',
            description: 'Send the quote to the customer (mock email).',
            routeName: 'quote.index',
            selector: '[data-testid="demo-quote-send"]',
            placement: 'left',
            order: 6,
            group: 'Quotes',
            completion: ['type' => 'event', 'event' => 'demo:quote_sent']
        );
        $steps[] = $this->step(
            key: 'quote_accept',
            title: 'Accept the quote',
            description: 'Mark the quote as accepted to open the job pipeline.',
            routeName: 'quote.index',
            selector: '[data-testid="demo-quote-accept"]',
            placement: 'left',
            order: 7,
            group: 'Quotes',
            completion: ['type' => 'event', 'event' => 'demo:quote_accepted']
        );
        $steps[] = $this->step(
            key: 'quote_convert',
            title: 'Convert to a job',
            description: 'Convert the accepted quote into a job to schedule work.',
            routeName: 'quote.index',
            selector: '[data-testid="demo-quote-convert"]',
            placement: 'left',
            order: 8,
            group: 'Jobs',
            completion: ['type' => 'event', 'event' => 'demo:quote_converted']
        );
        $steps[] = $this->step(
            key: 'work_recurrence',
            title: 'Plan recurring visits',
            description: 'Set a recurrence schedule for the job visits.',
            routeName: 'work.edit',
            selector: '[data-testid="demo-work-recurrence"]',
            placement: 'right',
            order: 9,
            group: 'Jobs',
            completion: ['type' => 'manual'],
            routeParams: ['work' => 'demo_work']
        );
        $steps[] = $this->step(
            key: 'work_calendar',
            title: 'Review the calendar',
            description: 'Review scheduled tasks and jobs on the calendar.',
            routeName: 'work.edit',
            selector: '[data-testid="demo-work-calendar"]',
            placement: 'left',
            order: 10,
            group: 'Jobs',
            completion: ['type' => 'manual'],
            routeParams: ['work' => 'demo_work']
        );
        $steps[] = $this->step(
            key: 'task_assign',
            title: 'Assign a task',
            description: 'Create a task and assign it to a team member.',
            routeName: 'task.index',
            selector: '[data-testid="demo-task-add"]',
            placement: 'bottom',
            order: 11,
            group: 'Tasks',
            completion: ['type' => 'event', 'event' => 'demo:task_assigned']
        );
        $steps[] = $this->step(
            key: 'task_complete',
            title: 'Complete a task',
            description: 'Mark a task as done to close the loop.',
            routeName: 'task.index',
            selector: '[data-testid="demo-task-mark-done"]',
            placement: 'left',
            order: 12,
            group: 'Tasks',
            completion: ['type' => 'event', 'event' => 'demo:task_completed']
        );
        $steps[] = $this->step(
            key: 'invoice_create',
            title: 'Generate an invoice',
            description: 'Create an invoice from the job.',
            routeName: 'work.show',
            selector: '[data-testid="demo-create-invoice"]',
            placement: 'bottom',
            order: 13,
            group: 'Invoices',
            completion: ['type' => 'event', 'event' => 'demo:invoice_created'],
            routeParams: ['work' => 'demo_work']
        );
        $steps[] = $this->step(
            key: 'invoice_paid',
            title: 'Record a payment',
            description: 'Mark the invoice as paid using the demo payment form.',
            routeName: 'invoice.show',
            selector: '[data-testid="demo-invoice-payment-submit"]',
            placement: 'right',
            order: 14,
            group: 'Invoices',
            completion: ['type' => 'event', 'event' => 'demo:invoice_paid'],
            routeParams: ['invoice' => 'demo_invoice']
        );
        $steps[] = $this->step(
            key: 'catalog_services',
            title: 'Browse the catalog',
            description: 'Check the services catalog and search filters.',
            routeName: 'service.index',
            selector: '[data-testid="demo-service-search"]',
            placement: 'bottom',
            order: 15,
            group: 'Catalog',
            completion: ['type' => 'manual']
        );
        $steps[] = $this->step(
            key: 'settings_update',
            title: 'Update a setting',
            description: 'Save a setting safely in demo mode.',
            routeName: 'settings.company.edit',
            selector: '[data-testid="demo-settings-save"]',
            placement: 'bottom',
            order: 16,
            group: 'Settings',
            completion: ['type' => 'event', 'event' => 'demo:settings_saved']
        );
        $steps[] = $this->step(
            key: 'search_filters',
            title: 'Search & filter',
            description: 'Use filters to quickly find the right quote.',
            routeName: 'quote.index',
            selector: '[data-testid="demo-quote-search"]',
            placement: 'bottom',
            order: 17,
            group: 'Quotes',
            completion: ['type' => 'manual']
        );
        $steps[] = $this->step(
            key: 'activity_log',
            title: 'Review activity',
            description: 'Track recent activity and completed work.',
            routeName: 'dashboard',
            selector: '[data-testid="demo-dashboard-activity"]',
            placement: 'top',
            order: 18,
            group: 'Dashboard',
            completion: ['type' => 'manual']
        );
        $steps[] = $this->step(
            key: 'demo_checklist',
            title: 'Demo checklist',
            description: 'Use the checklist to see what is complete.',
            routeName: 'demo.checklist',
            selector: '[data-testid="demo-checklist-summary"]',
            placement: 'bottom',
            order: 19,
            group: 'Wrap-up',
            completion: ['type' => 'manual']
        );
        $steps[] = $this->step(
            key: 'tour_recap',
            title: 'All set',
            description: 'You have completed the guided tour. Explore the rest of the platform anytime.',
            routeName: 'dashboard',
            selector: null,
            placement: 'center',
            order: 20,
            group: 'Wrap-up',
            completion: ['type' => 'manual']
        );

        return $steps;
    }

    private function step(
        string $key,
        string $title,
        string $description,
        string $routeName,
        ?string $selector,
        string $placement,
        int $order,
        string $group,
        array $completion,
        ?array $routeParams = null,
        bool $required = true
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'route_name' => $routeName,
            'selector' => $selector,
            'placement' => $placement,
            'order_index' => $order,
            'payload_json' => [
                'group' => $group,
                'completion' => $completion,
                'route_params' => $routeParams,
            ],
            'is_required' => $required,
        ];
    }

    private function demoAddresses(): array
    {
        return [
            [
                'country' => 'US',
                'street1' => '120 Market Street',
                'street2' => null,
                'city' => 'San Francisco',
                'state' => 'CA',
                'zip' => '94105',
            ],
            [
                'country' => 'US',
                'street1' => '530 Maple Ave',
                'street2' => null,
                'city' => 'Seattle',
                'state' => 'WA',
                'zip' => '98101',
            ],
            [
                'country' => 'US',
                'street1' => '88 Lakeview Blvd',
                'street2' => null,
                'city' => 'Chicago',
                'state' => 'IL',
                'zip' => '60601',
            ],
            [
                'country' => 'US',
                'street1' => '455 Sunset Rd',
                'street2' => null,
                'city' => 'Phoenix',
                'state' => 'AZ',
                'zip' => '85001',
            ],
            [
                'country' => 'US',
                'street1' => '19 Ridge Drive',
                'street2' => null,
                'city' => 'Denver',
                'state' => 'CO',
                'zip' => '80202',
            ],
        ];
    }

    private function guidedCustomerEmail(int $accountId, string $domain): string
    {
        return "guided-demo-customer-{$accountId}@{$domain}";
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            DemoAccountService::TYPE_SERVICE,
            DemoAccountService::TYPE_PRODUCT,
            DemoAccountService::TYPE_GUIDED => $type,
            'service_demo' => DemoAccountService::TYPE_SERVICE,
            'product_demo' => DemoAccountService::TYPE_PRODUCT,
            'guided_demo' => DemoAccountService::TYPE_GUIDED,
            default => DemoAccountService::TYPE_SERVICE,
        };
    }
}
