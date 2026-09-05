<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\InvoiceDocumentService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('invoice pdf download is available to the owner', function () {
    Notification::fake();

    $user = User::factory()->create();

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Test Co',
        'email' => 'billing@example.com',
        'salutation' => 'Mr',
    ]);

    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Service A',
        'price' => 150,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Test job',
        'instructions' => 'Handle service',
        'start_date' => now()->toDateString(),
        'subtotal' => 300,
        'total' => 300,
    ]);

    $work->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 150,
            'total' => 300,
            'description' => 'Service work',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $response = $this->actingAs($user)->get(route('invoice.pdf', $invoice));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('invoice pdf download is forbidden for non-owners', function () {
    Notification::fake();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'company_name' => 'Test Co',
        'email' => 'billing@example.com',
        'salutation' => 'Mr',
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Test job',
        'instructions' => 'Handle service',
        'start_date' => now()->toDateString(),
        'subtotal' => 100,
        'total' => 100,
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $this->actingAs($otherUser)
        ->getJson(route('invoice.pdf', $invoice))
        ->assertForbidden();
});

test('invoice pdf download supports the clean professional owner template', function () {
    Notification::fake();

    $user = User::factory()->create([
        'company_store_settings' => [
            'invoice_template_key' => 'clean_professional',
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Template',
        'last_name' => 'Customer',
        'company_name' => 'Template Co',
        'email' => 'template-billing@example.com',
        'salutation' => 'Mr',
    ]);

    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Service B',
        'price' => 175,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Template job',
        'instructions' => 'Handle template service',
        'start_date' => now()->toDateString(),
        'subtotal' => 350,
        'total' => 350,
    ]);

    $work->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 175,
            'total' => 350,
            'description' => 'Template service work',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $response = $this->actingAs($user)->get(route('invoice.pdf', $invoice));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('invoice pdf download supports the minimal corporate owner template', function () {
    Notification::fake();

    $user = User::factory()->create([
        'company_store_settings' => [
            'invoice_template_key' => 'minimal_corporate',
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Minimal',
        'last_name' => 'Client',
        'company_name' => 'Minimal Co',
        'email' => 'minimal-billing@example.com',
        'salutation' => 'Mr',
    ]);

    $category = ProductCategory::factory()->create();
    $product = Product::create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Service C',
        'price' => 210,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    $work = Work::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Minimal job',
        'instructions' => 'Handle minimal template service',
        'start_date' => now()->toDateString(),
        'subtotal' => 420,
        'total' => 420,
    ]);

    $work->products()->sync([
        $product->id => [
            'quantity' => 2,
            'price' => 210,
            'total' => 420,
            'description' => 'Minimal template service work',
        ],
    ]);

    $this->actingAs($user)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::where('work_id', $work->id)->first();
    expect($invoice)->not->toBeNull();

    $response = $this->actingAs($user)->get(route('invoice.pdf', $invoice));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('invoice documents use the account owner branding and template for employees', function () {
    $owner = User::factory()->create([
        'company_name' => 'Atelier du Nord',
        'company_logo' => 'https://assets.example.test/atelier-du-nord.png',
        'company_store_settings' => [
            'invoice_template_key' => 'minimal_corporate',
        ],
    ]);
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $employee = User::factory()->create([
        'role_id' => $employeeRole->id,
        'company_name' => 'Incorrect Employee Brand',
        'company_logo' => 'https://assets.example.test/employee.png',
        'company_store_settings' => [
            'invoice_template_key' => 'clean_professional',
        ],
    ]);
    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'employee',
        'permissions' => ['invoices.view'],
        'is_active' => true,
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => 'sent',
        'subtotal' => 25,
        'tax_total' => 0,
        'total' => 25,
        'currency_code' => 'CAD',
    ]);

    $service = app(InvoiceDocumentService::class);
    $preparedInvoice = $service->prepareInvoice($invoice);
    $buildViewData = new ReflectionMethod($service, 'buildViewData');
    $buildViewData->setAccessible(true);
    $viewData = $buildViewData->invoke($service, $preparedInvoice, $employee, null);

    expect($service->templateKeyFor($employee))->toBe('minimal_corporate')
        ->and($viewData['company']->is($owner))->toBeTrue();
});

test('all invoice templates render snapshot taxes net tips and net charged totals', function () {
    $owner = User::factory()->create([
        'company_name' => 'Atelier Boréal',
        'company_logo' => 'https://assets.example.test/atelier-boreal-wide.png',
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
    ]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'paid',
        'approval_status' => 'approved',
        'subtotal' => 35,
        'tax_total' => 5.24,
        'total' => 40.24,
        'currency_code' => 'CAD',
    ]);
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Divergent legacy line',
        'quantity' => 1,
        'unit_price' => 99,
        'total' => 99,
        'currency_code' => 'CAD',
    ]);
    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 40.24,
        'tip_amount' => 7.25,
        'tip_reversed_amount' => 2,
        'charged_total' => 47.49,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    $service = app(InvoiceDocumentService::class);
    $preparedInvoice = $service->prepareInvoice($invoice);
    $buildViewData = new ReflectionMethod($service, 'buildViewData');
    $buildViewData->setAccessible(true);
    $viewData = $buildViewData->invoke($service, $preparedInvoice, $owner, 'modern');

    foreach (['pdf.invoice', 'pdf.invoice-clean', 'pdf.invoice-minimal'] as $view) {
        $html = str_replace(',', '.', view($view, $viewData)->render());

        expect($html)->toContain('35.00')
            ->and($html)->toContain('5.24')
            ->and($html)->toContain('40.24')
            ->and($html)->toContain('5.25')
            ->and($html)->toContain('45.49')
            ->and($html)->toContain('Atelier Boréal')
            ->and($html)->toContain('https://assets.example.test/atelier-boreal-wide.png')
            ->and($html)->toContain('object-fit: contain')
            ->and($html)->not->toContain('customers/customer.png');
    }
});

test('public receipts require a valid signature and a paid invoice', function () {
    $owner = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => 'sent',
        'subtotal' => 20,
        'tax_total' => 0,
        'total' => 20,
        'currency_code' => 'CAD',
    ]);

    $this->getJson(route('public.invoices.receipt', $invoice))->assertForbidden();

    $signedUrl = URL::temporarySignedRoute(
        'public.invoices.receipt',
        now()->addMinutes(5),
        ['invoice' => $invoice->id]
    );
    $this->get($signedUrl)->assertNotFound();

    $invoice->forceFill(['status' => 'paid'])->save();
    $response = $this->get($signedUrl)->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('cache-control'))->toContain('private')
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});
