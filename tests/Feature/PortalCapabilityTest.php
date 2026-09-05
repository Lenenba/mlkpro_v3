<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Services\Portal\PortalCapabilityService;
use App\Services\WorkBillingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

function portalCapabilityRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role'],
    )->id;
}

/**
 * @param  array<string, bool>  $overrides
 * @return array<string, bool>
 */
function portalCapabilityFeatureSet(array $overrides = []): array
{
    return array_replace([
        'quotes' => false,
        'requests' => false,
        'reservations' => false,
        'plan_scans' => false,
        'invoices' => false,
        'jobs' => false,
        'products' => false,
        'performance' => false,
        'presence' => false,
        'planning' => false,
        'sales' => false,
        'sales_crm' => false,
        'promotions' => false,
        'expenses' => false,
        'accounting' => false,
        'services' => false,
        'tasks' => false,
        'team_members' => false,
        'assistant' => false,
        'campaigns' => false,
        'social' => false,
        'loyalty' => false,
    ], $overrides);
}

/**
 * @param  array<string, bool>  $features
 * @param  array<string, mixed>  $ownerAttributes
 * @param  array<string, mixed>  $customerAttributes
 * @param  array<string, mixed>  $clientAttributes
 * @return array{owner: User, client: User, customer: Customer}
 */
function portalCapabilityContext(
    array $features = [],
    array $ownerAttributes = [],
    array $customerAttributes = [],
    array $clientAttributes = [],
): array {
    $owner = User::factory()->create(array_replace([
        'role_id' => portalCapabilityRoleId('owner'),
        'company_name' => 'Portal Capability Owner',
        'company_type' => 'services',
        'company_sector' => null,
        'company_features' => portalCapabilityFeatureSet($features),
        'onboarding_completed_at' => now(),
    ], $ownerAttributes));

    $client = User::factory()->create(array_replace([
        'role_id' => portalCapabilityRoleId('client'),
        'company_name' => null,
        'company_type' => null,
        'company_sector' => null,
        'company_features' => [],
        'onboarding_completed_at' => now(),
    ], $clientAttributes));

    $customer = Customer::query()->create(array_replace([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'first_name' => 'Portal',
        'last_name' => 'Capability',
        'company_name' => 'Portal Capability Customer',
        'email' => $client->email,
        'phone' => '+15145550101',
        'auto_accept_quotes' => false,
        'auto_validate_jobs' => false,
        'auto_validate_tasks' => false,
        'auto_validate_invoices' => false,
    ], $customerAttributes));

    return compact('owner', 'client', 'customer');
}

/** @param array<string, mixed> $attributes */
function portalCapabilityQuote(User $owner, Customer $customer, array $attributes = []): Quote
{
    return Quote::query()->create(array_replace([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Portal capability quote',
        'status' => 'sent',
        'subtotal' => 200,
        'total' => 200,
        'initial_deposit' => 0,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function portalCapabilityWork(User $owner, Customer $customer, array $attributes = []): Work
{
    return Work::query()->create(array_replace([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Portal capability work',
        'instructions' => 'Portal capability test work',
        'status' => Work::STATUS_IN_PROGRESS,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function portalCapabilityInvoice(User $owner, Customer $customer, array $attributes = []): Invoice
{
    $work = portalCapabilityWork($owner, $customer, [
        'job_title' => 'Portal capability invoice work',
    ]);

    return Invoice::query()->create(array_replace([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => 150,
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function portalCapabilityTask(User $owner, Customer $customer, Work $work, array $attributes = []): Task
{
    return Task::query()->create(array_replace([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'title' => 'Portal capability task',
        'status' => Task::STATUS_IN_PROGRESS,
        'due_date' => now()->toDateString(),
    ], $attributes));
}

/** @param array<string, mixed> $attributes */
function portalCapabilitySale(User $owner, Customer $customer, array $attributes = []): Sale
{
    return Sale::query()->create(array_replace([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 75,
        'tax_total' => 0,
        'discount_rate' => 0,
        'discount_total' => 0,
        'total' => 75,
        'fulfillment_method' => 'delivery',
        'fulfillment_status' => Sale::FULFILLMENT_PENDING,
        'source' => 'portal',
    ], $attributes));
}

beforeEach(function () {
    Cache::flush();

    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('resolves portal capabilities from the owner linked by customer user id', function () {
    ['client' => $client] = portalCapabilityContext(
        features: ['reservations' => true],
        clientAttributes: [
            'company_type' => 'products',
            'company_features' => portalCapabilityFeatureSet([
                'products' => true,
                'sales' => true,
                'quotes' => true,
                'jobs' => true,
            ]),
        ],
    );

    $capabilities = app(PortalCapabilityService::class)->forUser($client);

    expect($capabilities)
        ->reservations->view->toBeTrue()
        ->orders->view->toBeFalse()
        ->quotes->view->toBeFalse()
        ->works->view->toBeFalse();
});

it('exposes a coherent reservation only matrix for a salon portal', function () {
    ['client' => $client] = portalCapabilityContext(
        features: ['reservations' => true],
        ownerAttributes: ['company_sector' => 'salon'],
    );

    $capabilities = app(PortalCapabilityService::class)->forUser($client);

    expect($capabilities)
        ->version->toBe(1)
        ->reservations->toBe([
            'view' => true,
            'book' => true,
            'manage' => true,
            'review' => true,
        ])
        ->orders->view->toBeFalse()
        ->quotes->view->toBeFalse()
        ->quotes->history->toBeTrue()
        ->works->view->toBeFalse()
        ->tasks->view->toBeFalse()
        ->invoices->view->toBeFalse()
        ->invoices->history->toBeTrue()
        ->packages->view->toBeFalse()
        ->notifications->view->toBeTrue();
});

it('derives the client experience from active portal capabilities', function (array $features, string $expectedMode) {
    ['client' => $client] = portalCapabilityContext($features);
    $service = app(PortalCapabilityService::class);

    $context = $service->context($service->forUser($client));

    expect($context['mode'])->toBe($expectedMode)
        ->and($context['has_service'])->toBe(in_array($expectedMode, ['service', 'hybrid'], true))
        ->and($context['has_product'])->toBe(in_array($expectedMode, ['product', 'hybrid'], true));
})->with([
    'minimal' => [[], 'minimal'],
    'service' => [['reservations' => true], 'service'],
    'product' => [['products' => true, 'sales' => true], 'product'],
    'hybrid' => [['reservations' => true, 'products' => true, 'sales' => true], 'hybrid'],
]);

it('requires products and sales for every order capability', function (bool $products, bool $sales, bool $expected) {
    ['client' => $client] = portalCapabilityContext([
        'products' => $products,
        'sales' => $sales,
    ]);

    $orders = app(PortalCapabilityService::class)->forUser($client)['orders'];

    expect($orders)->toBe(array_fill_keys([
        'view', 'history', 'create', 'update', 'pay', 'confirm', 'cancel', 'reorder', 'review',
    ], $expected));
})->with([
    'neither module' => [false, false, false],
    'products only' => [true, false, false],
    'sales only' => [false, true, false],
    'products and sales' => [true, true, true],
]);

it('applies the quotes and jobs dependency matrix', function (bool $quotes, bool $jobs, bool $canAccept) {
    ['client' => $client] = portalCapabilityContext([
        'quotes' => $quotes,
        'jobs' => $jobs,
    ]);

    $capabilities = app(PortalCapabilityService::class)->forUser($client);

    expect($capabilities)
        ->quotes->view->toBe($quotes)
        ->quotes->history->toBeTrue()
        ->quotes->accept->toBe($canAccept)
        ->quotes->decline->toBe($quotes)
        ->works->view->toBe($jobs);
})->with([
    'neither module' => [false, false, false],
    'quotes only' => [true, false, false],
    'jobs only' => [false, true, false],
    'quotes and jobs' => [true, true, true],
]);

it('applies the jobs and tasks dependency matrix', function (bool $jobs, bool $tasks, bool $expected) {
    ['client' => $client] = portalCapabilityContext([
        'jobs' => $jobs,
        'tasks' => $tasks,
    ]);

    $capabilities = app(PortalCapabilityService::class)->forUser($client);

    expect($capabilities)
        ->works->view->toBe($jobs)
        ->works->schedule->toBe($expected)
        ->works->proofs->toBe($expected)
        ->tasks->view->toBe($expected)
        ->tasks->upload->toBe($expected);
})->with([
    'neither module' => [false, false, false],
    'jobs only' => [true, false, false],
    'tasks only' => [false, true, false],
    'jobs and tasks' => [true, true, true],
]);

it('limits only the actions controlled by customer auto validation settings', function () {
    ['client' => $client] = portalCapabilityContext(
        features: [
            'quotes' => true,
            'jobs' => true,
            'tasks' => true,
            'invoices' => true,
        ],
        customerAttributes: [
            'auto_accept_quotes' => true,
            'auto_validate_jobs' => true,
            'auto_validate_tasks' => true,
            'auto_validate_invoices' => true,
        ],
    );

    $capabilities = app(PortalCapabilityService::class)->forUser($client);

    expect($capabilities)
        ->quotes->view->toBeTrue()
        ->quotes->accept->toBeFalse()
        ->quotes->decline->toBeFalse()
        ->quotes->rate->toBeTrue()
        ->works->view->toBeTrue()
        ->works->validate->toBeFalse()
        ->works->dispute->toBeFalse()
        ->works->schedule->toBeTrue()
        ->works->proofs->toBeTrue()
        ->works->rate->toBeTrue()
        ->tasks->view->toBeTrue()
        ->tasks->upload->toBeFalse()
        ->invoices->view->toBeTrue()
        ->invoices->history->toBeTrue()
        ->invoices->pay->toBeFalse();
});

it('returns an entirely unavailable matrix when portal access is disabled', function () {
    ['client' => $client] = portalCapabilityContext(
        features: [
            'reservations' => true,
            'products' => true,
            'sales' => true,
            'quotes' => true,
            'jobs' => true,
            'tasks' => true,
            'invoices' => true,
            'services' => true,
            'loyalty' => true,
        ],
        customerAttributes: ['portal_access' => false],
    );

    $capabilities = app(PortalCapabilityService::class)->forUser($client);
    $values = collect($capabilities)
        ->except('version')
        ->flatMap(static fn (array $actions): array => array_values($actions));

    expect($capabilities['version'])->toBe(1)
        ->and($values)->not->toBeEmpty()
        ->and($values->every(static fn (mixed $value): bool => $value === false))->toBeTrue();
});

it('rejects quote acceptance through the api when jobs are disabled without creating work', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext([
        'quotes' => true,
        'jobs' => false,
    ]);
    $quote = portalCapabilityQuote($owner, $customer);

    Sanctum::actingAs($client);

    $this->postJson("/api/v1/portal/quotes/{$quote->id}/accept")
        ->assertForbidden()
        ->assertJsonPath('code', 'portal_capability_unavailable')
        ->assertJsonPath('message', __('ui.portal.capability_unavailable'))
        ->assertJsonPath('capability', 'quotes.accept');

    expect($quote->fresh()->status)->toBe('sent');
    $this->assertDatabaseMissing('works', ['quote_id' => $quote->id]);
});

it('rejects schedule confirmation through the api when tasks are disabled without creating a task', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext([
        'jobs' => true,
        'tasks' => false,
    ]);
    $work = portalCapabilityWork($owner, $customer, [
        'status' => Work::STATUS_SCHEDULED,
        'start_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($client);

    $this->postJson("/api/v1/portal/works/{$work->id}/schedule/confirm")
        ->assertForbidden()
        ->assertJsonPath('code', 'portal_capability_unavailable')
        ->assertJsonPath('message', __('ui.portal.capability_unavailable'))
        ->assertJsonPath('capability', 'works.schedule');

    $this->assertDatabaseMissing('tasks', ['work_id' => $work->id]);
});

it('rejects invoice payment through the api when invoices are disabled without creating a payment', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext();
    $invoice = portalCapabilityInvoice($owner, $customer);

    Sanctum::actingAs($client);

    $this->postJson("/api/v1/portal/invoices/{$invoice->id}/payments", [
        'amount' => 25,
        'method' => 'cash',
    ])
        ->assertForbidden()
        ->assertJsonPath('code', 'portal_capability_unavailable')
        ->assertJsonPath('message', __('ui.portal.capability_unavailable'))
        ->assertJsonPath('capability', 'invoices.pay');

    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
});

it('rejects invoice payment when customer automation disables the payment action', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext(
        features: ['invoices' => true],
        customerAttributes: ['auto_validate_invoices' => true],
    );
    $invoice = portalCapabilityInvoice($owner, $customer);

    Sanctum::actingAs($client);

    $this->postJson("/api/v1/portal/invoices/{$invoice->id}/payments", [
        'amount' => 25,
        'method' => 'cash',
    ])
        ->assertForbidden()
        ->assertJsonPath('capability', 'invoices.pay');

    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
});

it('checks portal capabilities before resolving bound resources', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext();
    $invoice = portalCapabilityInvoice($owner, $customer);

    Sanctum::actingAs($client);

    $this->getJson("/api/v1/portal/orders/{$invoice->id}")
        ->assertForbidden()
        ->assertJsonPath('capability', 'orders.history');
    $this->getJson('/api/v1/portal/orders/999999999')
        ->assertForbidden()
        ->assertJsonPath('capability', 'orders.history');
});

it('redirects an unavailable html capability with a localized warning', function () {
    ['client' => $client] = portalCapabilityContext();

    $this->actingAs($client)
        ->from(route('dashboard'))
        ->get(route('portal.orders.index'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('warning', __('ui.portal.capability_unavailable'));
});

it('keeps quote and invoice history available after their current modules are disabled', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext();
    $quote = portalCapabilityQuote($owner, $customer, [
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);
    $invoice = portalCapabilityInvoice($owner, $customer);

    $this->actingAs($client)
        ->get(route('portal.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/InvoicesIndex')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $invoice->id)
        );

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('portalCapabilities.quotes.view', false)
        ->assertJsonPath('portalCapabilities.quotes.history', true)
        ->assertJsonPath('portalCapabilities.invoices.view', false)
        ->assertJsonPath('portalCapabilities.invoices.history', true)
        ->assertJsonCount(0, 'pendingQuotes')
        ->assertJsonCount(1, 'validatedQuotes')
        ->assertJsonPath('validatedQuotes.0.id', $quote->id)
        ->assertJsonCount(0, 'invoicesDue');
});

it('filters dashboard data whose capabilities are disabled', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext();
    portalCapabilityQuote($owner, $customer);
    portalCapabilityWork($owner, $customer, ['status' => Work::STATUS_PENDING_REVIEW]);
    portalCapabilityWork($owner, $customer, ['status' => Work::STATUS_SCHEDULED]);
    $validatedWork = portalCapabilityWork($owner, $customer, ['status' => Work::STATUS_VALIDATED]);
    portalCapabilityTask($owner, $customer, $validatedWork);
    portalCapabilityInvoice($owner, $customer);

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('portalCapabilities.quotes.view', false)
        ->assertJsonPath('portalCapabilities.works.view', false)
        ->assertJsonPath('portalCapabilities.tasks.view', false)
        ->assertJsonPath('portalCapabilities.invoices.pay', false)
        ->assertJsonPath('stats.quotes_pending', 0)
        ->assertJsonPath('stats.works_pending', 0)
        ->assertJsonPath('stats.invoices_due', 0)
        ->assertJsonPath('stats.ratings_due', 0)
        ->assertJsonCount(0, 'pendingQuotes')
        ->assertJsonCount(0, 'pendingSchedules')
        ->assertJsonCount(0, 'pendingWorks')
        ->assertJsonCount(0, 'validatedWorks')
        ->assertJsonCount(0, 'taskProofs')
        ->assertJsonCount(0, 'invoicesDue')
        ->assertJsonCount(0, 'quoteRatingsDue')
        ->assertJsonCount(0, 'workRatingsDue');
});

it('revokes cached dashboard data immediately when owner modules are disabled', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext([
        'quotes' => true,
        'jobs' => true,
        'tasks' => true,
        'invoices' => true,
    ]);
    $quote = portalCapabilityQuote($owner, $customer);
    $work = portalCapabilityWork($owner, $customer, ['status' => Work::STATUS_PENDING_REVIEW]);
    portalCapabilityTask($owner, $customer, $work);
    $invoice = portalCapabilityInvoice($owner, $customer);

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('portalCapabilities.quotes.view', true)
        ->assertJsonPath('pendingQuotes.0.id', $quote->id)
        ->assertJsonPath('pendingWorks.0.id', $work->id)
        ->assertJsonPath('taskProofs.0.work_id', $work->id)
        ->assertJsonPath('invoicesDue.0.id', $invoice->id);

    $owner->forceFill([
        'company_features' => portalCapabilityFeatureSet(),
    ])->save();

    $this->getJson('/api/v1/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('portalCapabilities.quotes.view', false)
        ->assertJsonPath('portalCapabilities.works.view', false)
        ->assertJsonPath('portalCapabilities.tasks.view', false)
        ->assertJsonPath('portalCapabilities.invoices.pay', false)
        ->assertJsonCount(0, 'pendingQuotes')
        ->assertJsonCount(0, 'pendingWorks')
        ->assertJsonCount(0, 'taskProofs')
        ->assertJsonCount(0, 'invoicesDue');
});

it('exposes the portal capability map in the client auth bootstrap payload', function () {
    ['client' => $client] = portalCapabilityContext([
        'reservations' => true,
    ]);

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('meta.portal_capabilities.version', 1)
        ->assertJsonPath('meta.portal_capabilities.reservations.view', true)
        ->assertJsonPath('meta.portal_capabilities.reservations.book', true)
        ->assertJsonPath('meta.portal_capabilities.orders.view', false)
        ->assertJsonPath('meta.portal_capabilities.quotes.history', true)
        ->assertJsonPath('meta.portal_capabilities.invoices.history', true)
        ->assertJsonPath('meta.portal_context.mode', 'service')
        ->assertJsonPath('meta.portal_context.has_service', true)
        ->assertJsonPath('meta.portal_context.has_product', false);
});

it('shares the portal capability map with inertia client navigation', function () {
    ['client' => $client] = portalCapabilityContext([
        'reservations' => true,
    ]);

    $this->actingAs($client)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardClient')
            ->where('auth.account.portal_capabilities.version', 1)
            ->where('auth.account.portal_capabilities.reservations.view', true)
            ->where('auth.account.portal_capabilities.orders.view', false)
            ->where('auth.account.portal_context.mode', 'service')
            ->where('portalCapabilities.reservations.view', true)
            ->where('portalContext.mode', 'service')
        );
});

it('renders a product dashboard from capabilities instead of company type', function () {
    ['client' => $client] = portalCapabilityContext(
        features: ['products' => true, 'sales' => true],
        ownerAttributes: ['company_type' => 'services'],
    );

    $this->actingAs($client)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardProductsClient')
            ->where('portalContext.mode', 'product')
            ->where('portalContext.has_service', false)
            ->where('portalContext.has_product', true)
        );

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('portalContext.mode', 'product')
        ->assertJsonPath('stats.quotes_pending', 0)
        ->assertJsonPath('orderOverview.stats.orders_total', 0)
        ->assertJsonMissingPath('stats.orders_total');
});

it('renders service and order summaries together for a hybrid client', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext(
        features: ['reservations' => true, 'products' => true, 'sales' => true],
        ownerAttributes: ['company_type' => 'products'],
    );
    $sale = portalCapabilitySale($owner, $customer);

    $this->actingAs($client)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('DashboardClient')
            ->where('portalContext.mode', 'hybrid')
            ->where('orderOverview.stats.orders_total', 1)
            ->where('orderOverview.stats.orders_pending', 1)
            ->where('orderOverview.sales.0.id', $sale->id)
            ->where('orderOverview.sales.0.payment_status', 'unpaid')
            ->missing('orderOverview.sales.0.payments')
            ->where('portalCapabilities.reservations.view', true)
            ->where('portalCapabilities.orders.view', true)
        );

    Sanctum::actingAs($client);

    $this->getJson('/api/v1/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('portalContext.mode', 'hybrid')
        ->assertJsonPath('stats.orders_total', 1)
        ->assertJsonPath('sales.0.id', $sale->id)
        ->assertJsonPath('sales.0.payment_status', 'unpaid')
        ->assertJsonMissingPath('sales.0.payments')
        ->assertJsonMissingPath('orderOverview');
});

it('applies the same capability boundary to signed public links', function () {
    ['owner' => $owner, 'customer' => $customer] = portalCapabilityContext();
    $quote = portalCapabilityQuote($owner, $customer);
    $work = portalCapabilityWork($owner, $customer, [
        'status' => Work::STATUS_SCHEDULED,
        'start_date' => now()->toDateString(),
    ]);
    $task = portalCapabilityTask($owner, $customer, $work);
    $invoice = portalCapabilityInvoice($owner, $customer);
    $expiresAt = now()->addMinutes(30);

    $this->get(URL::temporarySignedRoute('public.quotes.show', $expiresAt, ['quote' => $quote]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/QuoteAction')
            ->where('allowAccept', false)
            ->where('allowDecline', false)
        );

    $signedInvoiceUrl = URL::temporarySignedRoute('public.invoices.show', $expiresAt, ['invoice' => $invoice]);
    $this->get($signedInvoiceUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/InvoicePay')
            ->where('allowPayment', false)
        );
    $this->get($signedInvoiceUrl.'&session_id=checkout_session_from_stripe')
        ->assertOk();
    $this->getJson($signedInvoiceUrl.'&unexpected=tampered')
        ->assertForbidden();

    $this->getJson(URL::temporarySignedRoute('public.works.show', $expiresAt, ['work' => $work]))
        ->assertForbidden();
    $this->getJson(URL::temporarySignedRoute('public.works.proofs', $expiresAt, ['work' => $work]))
        ->assertForbidden();
    $this->postJson(URL::temporarySignedRoute('public.quotes.accept', $expiresAt, ['quote' => $quote]))
        ->assertForbidden();
    $this->postJson(URL::temporarySignedRoute('public.works.schedule.confirm', $expiresAt, ['work' => $work]))
        ->assertForbidden();
    $this->postJson(URL::temporarySignedRoute('public.tasks.media.store', $expiresAt, ['task' => $task]))
        ->assertForbidden();
    $this->postJson(URL::temporarySignedRoute('public.invoices.pay', $expiresAt, ['invoice' => $invoice]), [
        'amount' => 25,
        'method' => 'cash',
    ])->assertForbidden();

    expect($quote->fresh()->status)->toBe('sent')
        ->and($work->fresh()->status)->toBe(Work::STATUS_SCHEDULED);
    $this->assertDatabaseMissing('task_media', ['task_id' => $task->id]);
    $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
    $this->assertDatabaseMissing('works', ['quote_id' => $quote->id]);
});

it('checks a public signature before resolving bound resources', function () {
    ['owner' => $owner, 'customer' => $customer] = portalCapabilityContext();
    $invoice = portalCapabilityInvoice($owner, $customer);

    $this->getJson(route('public.invoices.show', $invoice))
        ->assertForbidden();
    $this->getJson(route('public.invoices.show', 999999999))
        ->assertForbidden();
});

it('does not synchronize a stripe checkout session for another invoice', function () {
    ['owner' => $owner, 'customer' => $customer] = portalCapabilityContext([
        'invoices' => true,
    ]);
    $expectedInvoice = portalCapabilityInvoice($owner, $customer);
    $otherInvoice = portalCapabilityInvoice($owner, $customer);

    $payment = app(\App\Services\StripeInvoiceService::class)->recordPaymentFromCheckoutSession([
        'id' => 'cs_portal_capability_mismatch',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_portal_capability_mismatch',
        'amount_total' => 15000,
        'client_reference_id' => (string) $otherInvoice->id,
        'metadata' => [
            'invoice_id' => (string) $otherInvoice->id,
            'payment_amount' => '150.00',
        ],
    ], expectedInvoice: $expectedInvoice);

    expect($payment)->toBeNull();
    $this->assertDatabaseMissing('payments', [
        'provider_reference' => 'pi_portal_capability_mismatch',
    ]);
});

it('does not generate an invoice when a client validates a work with invoices disabled', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext([
        'jobs' => true,
        'invoices' => false,
    ]);
    $work = portalCapabilityWork($owner, $customer, [
        'status' => Work::STATUS_PENDING_REVIEW,
        'total' => 180,
    ]);

    $this->actingAs($client)
        ->postJson(route('portal.works.validate', $work))
        ->assertOk()
        ->assertJsonPath('work.status', Work::STATUS_VALIDATED);

    $this->assertDatabaseMissing('invoices', ['work_id' => $work->id]);
});

it('attributes a portal generated invoice to the account owner', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext([
        'jobs' => true,
        'invoices' => true,
    ]);
    $work = portalCapabilityWork($owner, $customer, [
        'status' => Work::STATUS_PENDING_REVIEW,
        'total' => 180,
    ]);

    $this->actingAs($client)
        ->postJson(route('portal.works.validate', $work))
        ->assertOk();

    $this->assertDatabaseHas('invoices', [
        'work_id' => $work->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $this->assertDatabaseMissing('invoices', [
        'work_id' => $work->id,
        'created_by_user_id' => $client->id,
    ]);
});

it('rolls back work validation when automatic invoice creation fails', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = portalCapabilityContext([
        'jobs' => true,
        'invoices' => true,
    ]);
    $work = portalCapabilityWork($owner, $customer, [
        'status' => Work::STATUS_PENDING_REVIEW,
        'total' => 180,
    ]);
    $billingService = Mockery::mock(WorkBillingService::class);
    $billingService
        ->shouldReceive('createInvoiceFromWork')
        ->once()
        ->andThrow(new RuntimeException('Billing failed.'));
    $this->app->instance(WorkBillingService::class, $billingService);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($client)->postJson(route('portal.works.validate', $work)))
        ->toThrow(RuntimeException::class, 'Billing failed.');

    expect($work->fresh()->status)->toBe(Work::STATUS_PENDING_REVIEW);
    $this->assertDatabaseMissing('invoices', ['work_id' => $work->id]);
});
