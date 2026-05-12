<?php

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\CustomerBehaviorEvent;
use App\Models\CustomerConsent;
use App\Models\CustomerOptOut;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\SavedSegment;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\CampaignInAppNotification;
use App\Notifications\CustomerPackageBillingNotification;
use App\Services\Campaigns\AudienceResolver;
use App\Services\OfferPackages\CustomerPackageAutomationService;
use App\Services\OfferPackages\CustomerPackageService;
use App\Services\Segments\SegmentResolverRegistry;
use App\Services\StripeInvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Stripe\StripeClient;

function customerPackagesPhaseFiveOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'company_features' => [
            'customers' => true,
            'quotes' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
        ],
    ], $overrides));
}

function customerPackagesPhaseFiveRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function customerPackagesPhaseFiveClient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => customerPackagesPhaseFiveRoleId('client'),
        'email' => 'package-client-'.Str::lower(Str::random(10)).'@example.com',
        'notification_settings' => [
            'channels' => ['in_app' => true, 'push' => true],
            'categories' => ['billing' => true],
        ],
    ], $overrides));
}

function customerPackagesPhaseFiveProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Phase 5 catalog',
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Recurring service',
        'description' => 'Service included in a recurring forfait',
        'price' => 90,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ], $overrides));
}

function customerPackagesPhaseFiveOffer(User $owner, Product $product, array $overrides = []): OfferPackage
{
    $offer = OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Recurring monthly forfait',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Monthly recurring balance',
        'price' => 360,
        'currency_code' => 'CAD',
        'validity_days' => null,
        'included_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'is_public' => true,
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'renewal_notice_days' => 7,
    ], $overrides));

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'description_snapshot' => $product->description,
        'quantity' => 4,
        'unit_price' => 90,
        'included' => true,
        'is_optional' => false,
        'sort_order' => 0,
    ]);

    return $offer->fresh('items');
}

function injectStripeInvoiceClient(StripeInvoiceService $service, StripeClient $client): void
{
    $reflection = new ReflectionProperty($service, 'client');
    $reflection->setAccessible(true);
    $reflection->setValue($service, $client);
}

it('creates and assigns a recurring forfait with a first renewal cycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-11 09:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);

    $this->actingAs($owner)
        ->postJson(route('offer-packages.store'), [
            'name' => 'Monthly beauty plan',
            'type' => OfferPackage::TYPE_FORFAIT,
            'status' => OfferPackage::STATUS_ACTIVE,
            'description' => 'A recurring forfait',
            'price' => 360,
            'currency_code' => 'CAD',
            'included_quantity' => 4,
            'unit_type' => OfferPackage::UNIT_SESSION,
            'is_public' => true,
            'is_recurring' => true,
            'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
            'renewal_notice_days' => 5,
            'carry_over_unused_balance' => true,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 4,
                'unit_price' => 90,
                'is_optional' => false,
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('offer.is_recurring', true)
        ->assertJsonPath('offer.recurrence_frequency', OfferPackage::RECURRENCE_MONTHLY)
        ->assertJsonPath('offer.carry_over_unused_balance', true);

    $offer = OfferPackage::query()->where('name', 'Monthly beauty plan')->firstOrFail();

    $this->actingAs($owner)
        ->postJson(route('customer.packages.store', $customer), [
            'offer_package_id' => $offer->id,
            'starts_at' => '2026-05-11',
        ])
        ->assertCreated()
        ->assertJsonPath('customerPackage.is_recurring', true)
        ->assertJsonPath('customerPackage.next_renewal_at', '2026-06-11T00:00:00.000000Z');

    $package = CustomerPackage::query()->firstOrFail();

    expect($package->is_recurring)->toBeTrue()
        ->and($package->recurrence_frequency)->toBe(OfferPackage::RECURRENCE_MONTHLY)
        ->and($package->current_period_starts_at->toDateString())->toBe('2026-05-11')
        ->and($package->current_period_ends_at->toDateString())->toBe('2026-06-10')
        ->and($package->next_renewal_at->toDateString())->toBe('2026-06-11')
        ->and($package->expires_at->toDateString())->toBe('2026-06-10')
        ->and(data_get($package->source_details, 'recurrence.frequency'))->toBe(OfferPackage::RECURRENCE_MONTHLY);

    Carbon::setTestNow();
});

it('renews a recurring customer forfait and closes the previous period', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.renew', [$customer, $package]), [
            'starts_at' => '2026-06-01',
            'initial_quantity' => 4,
            'price_paid' => 360,
            'note' => 'June renewal',
        ])
        ->assertCreated()
        ->assertJsonPath('customerPackage.renewed_from_customer_package_id', $package->id)
        ->assertJsonPath('customerPackage.current_period_ends_at', '2026-06-30T00:00:00.000000Z')
        ->assertJsonPath('customerPackage.next_renewal_at', '2026-07-01T00:00:00.000000Z');

    $renewed = CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->firstOrFail();

    expect($package->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and($renewed->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($renewed->renewal_count)->toBe(1)
        ->and($renewed->remaining_quantity)->toBe(4)
        ->and(data_get($renewed->metadata, 'note'))->toBe('June renewal')
        ->and(ActivityLog::query()->where('action', 'customer_package_renewed')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customerPackages.0.id', $renewed->id)
            ->where('customerPackages.0.is_recurring', true)
            ->where('customerPackages.0.next_renewal_at', '2026-07-01T00:00:00.000000Z'));

    Carbon::setTestNow();
});

it('carries unused recurring balance into the renewed period when enabled', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product, [
        'metadata' => [
            'recurrence' => [
                'carry_over_unused_balance' => true,
            ],
        ],
    ]);

    $packageService = app(CustomerPackageService::class);
    $package = $packageService->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $packageService->consume($owner, $customer, $package, [
        'quantity' => 2,
        'used_at' => '2026-05-15',
        'source' => 'test_usage',
    ]);

    $renewed = $packageService->renew($owner, $customer, $package, [
        'starts_at' => '2026-06-01',
    ]);

    $package->refresh();

    expect($package->remaining_quantity)->toBe(2)
        ->and($package->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and(data_get($package->metadata, 'recurrence.carried_over_quantity'))->toBe(2)
        ->and($renewed->initial_quantity)->toBe(6)
        ->and($renewed->remaining_quantity)->toBe(6)
        ->and(data_get($renewed->metadata, 'recurrence.period_allocation_quantity'))->toBe(4)
        ->and(data_get($renewed->metadata, 'recurrence.carried_over_quantity'))->toBe(2)
        ->and(data_get($renewed->metadata, 'recurrence.carry_over_unused_balance'))->toBeTrue();

    Carbon::setTestNow();
});

it('prepares recurring renewal reminders from automation', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-05-11 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-04-15',
        'expires_at' => '2026-06-30',
    ]);

    $this->artisan('offer-packages:automation --date=2026-05-11')
        ->expectsOutput('Offer package automation: expired 0, low balance alerts 0, marketing reminders 0, renewal reminders 1, renewal invoices 0, paid renewals 0, suspended renewals 0.')
        ->assertExitCode(0);

    expect(data_get($package->fresh()->metadata, 'automation.notifications.renewal_due_sent_at.sent_at'))->not->toBeNull()
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_due')->exists())->toBeTrue();

    Notification::assertSentTo($owner, CampaignInAppNotification::class);

    Carbon::setTestNow();
});

it('creates an idempotent renewal invoice for a recurring forfait', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.renewal-invoice', [$customer, $package]))
        ->assertCreated()
        ->assertJsonPath('invoice.customer_id', $customer->id)
        ->assertJsonPath('invoice.status', 'sent')
        ->assertJsonPath('invoice.total', '360.00');

    $package->refresh();
    $invoice = Invoice::query()->firstOrFail();
    $item = InvoiceItem::query()->firstOrFail();

    expect($invoice->total)->toEqual('360.00')
        ->and($item->invoice_id)->toBe($invoice->id)
        ->and(data_get($item->meta, 'source'))->toBe('customer_package_renewal')
        ->and(data_get($item->meta, 'renewal_for_customer_package_id'))->toBe($package->id)
        ->and($package->recurrence_status)->toBe(CustomerPackage::RECURRENCE_PAYMENT_DUE)
        ->and(data_get($package->metadata, 'recurrence.pending_invoice_id'))->toBe($invoice->id)
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_invoice_created')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->postJson(route('customer.packages.renewal-invoice', [$customer, $package]))
        ->assertCreated()
        ->assertJsonPath('invoice.id', $invoice->id);

    expect(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('customerPackages.0.renewal_invoice.id', $invoice->id)
            ->where('customerPackages.0.renewal_invoice.status', 'sent'));

    Carbon::setTestNow();
});

it('creates due renewal invoices from automation', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->artisan('offer-packages:automation --date=2026-06-01')
        ->expectsOutput('Offer package automation: expired 0, low balance alerts 0, marketing reminders 0, renewal reminders 1, renewal invoices 1, paid renewals 0, suspended renewals 0.')
        ->assertExitCode(0);

    expect(Invoice::query()->count())->toBe(1)
        ->and(data_get($package->fresh()->metadata, 'recurrence.pending_invoice_id'))->toBe(Invoice::query()->value('id'));

    Carbon::setTestNow();
});

it('renews a payment due forfait when its renewal invoice is paid', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner([
        'payment_methods' => ['card', 'cash'],
        'default_payment_method' => 'card',
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);
    $invoice = app(CustomerPackageService::class)->createRenewalInvoice($owner, $customer, $package);

    $this->actingAs($owner)
        ->postJson(route('payment.store', $invoice), [
            'amount' => 360,
            'method' => 'card',
        ])
        ->assertCreated()
        ->assertJsonPath('invoice.status', 'paid');

    $renewed = CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->where('invoice_id', $invoice->id)
        ->firstOrFail();

    expect(Payment::query()->where('invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and($package->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and($package->fresh()->recurrence_status)->toBe(CustomerPackage::RECURRENCE_ACTIVE)
        ->and(data_get($package->fresh()->metadata, 'recurrence.paid_invoice_id'))->toBe($invoice->id)
        ->and($renewed->starts_at->toDateString())->toBe('2026-06-01')
        ->and($renewed->next_renewal_at->toDateString())->toBe('2026-07-01')
        ->and($renewed->recurrence_status)->toBe(CustomerPackage::RECURRENCE_ACTIVE)
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_payment_received')->exists())->toBeTrue();

    app(CustomerPackageService::class)->renewFromPaidInvoice($invoice->fresh(), $owner);

    expect(CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->where('invoice_id', $invoice->id)
        ->count())->toBe(1);

    Carbon::setTestNow();
});

it('automatically charges a recurring renewal invoice with a reusable Stripe payment method', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));
    config()->set('services.stripe.enabled', true);
    config()->set('services.stripe.secret', 'sk_test_auto_renewal');

    $owner = customerPackagesPhaseFiveOwner([
        'payment_methods' => ['card'],
        'default_payment_method' => 'card',
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'stripe_customer_id' => 'cus_forfait_auto',
        'stripe_default_payment_method_id' => 'pm_forfait_auto',
    ]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $paymentIntents = new class
    {
        public array $payloads = [];

        public function create(array $payload, array $options = []): object
        {
            $this->payloads[] = ['payload' => $payload, 'options' => $options];

            return new class($payload)
            {
                public function __construct(private array $payload) {}

                public function toArray(): array
                {
                    return [
                        'id' => 'pi_forfait_auto_123',
                        'status' => 'succeeded',
                        'amount' => $this->payload['amount'],
                        'amount_received' => $this->payload['amount'],
                        'customer' => $this->payload['customer'],
                        'payment_method' => $this->payload['payment_method'],
                        'metadata' => $this->payload['metadata'],
                    ];
                }
            };
        }
    };

    $fakeClient = new class($paymentIntents) extends StripeClient
    {
        public function __construct(private object $paymentIntents)
        {
            parent::__construct('sk_test_auto_renewal');
        }

        public function getService($name)
        {
            return match ($name) {
                'paymentIntents' => $this->paymentIntents,
                default => throw new RuntimeException("Unexpected Stripe service [{$name}] in test."),
            };
        }
    };

    $stripeService = app(StripeInvoiceService::class);
    injectStripeInvoiceClient($stripeService, $fakeClient);
    app()->instance(StripeInvoiceService::class, $stripeService);

    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-01', 'UTC'));

    $invoice = Invoice::query()->firstOrFail();
    $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();
    $renewed = CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->where('invoice_id', $invoice->id)
        ->firstOrFail();
    $payload = $paymentIntents->payloads[0]['payload'];

    expect($summary['renewal_invoices'])->toBe(1)
        ->and($summary['stripe_payment_attempts'])->toBe(1)
        ->and($summary['stripe_payment_successes'])->toBe(1)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($payment->provider)->toBe('stripe')
        ->and($payment->provider_reference)->toBe('pi_forfait_auto_123')
        ->and($payload['customer'])->toBe('cus_forfait_auto')
        ->and($payload['payment_method'])->toBe('pm_forfait_auto')
        ->and($payload['off_session'])->toBeTrue()
        ->and($payload['confirm'])->toBeTrue()
        ->and($payload['metadata']['automatic_renewal'])->toBe('true')
        ->and($package->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and($renewed->starts_at->toDateString())->toBe('2026-06-01')
        ->and(data_get($package->fresh()->metadata, 'recurrence.auto_payment.last_status'))->toBe('succeeded')
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_auto_payment_succeeded')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('keeps the renewal invoice payable when no automatic Stripe method exists', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));
    config()->set('services.stripe.enabled', true);
    config()->set('services.stripe.secret', 'sk_test_auto_renewal');

    $owner = customerPackagesPhaseFiveOwner([
        'payment_methods' => ['card'],
        'default_payment_method' => 'card',
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-01', 'UTC'));
    $invoice = Invoice::query()->firstOrFail();

    expect($summary['renewal_invoices'])->toBe(1)
        ->and($summary['stripe_payment_attempts'])->toBe(0)
        ->and($summary['stripe_payment_fallbacks'])->toBe(1)
        ->and($invoice->status)->toBe('sent')
        ->and(Payment::query()->count())->toBe(0)
        ->and(CustomerPackage::query()->where('renewed_from_customer_package_id', $package->id)->exists())->toBeFalse()
        ->and($package->fresh()->recurrence_status)->toBe(CustomerPackage::RECURRENCE_PAYMENT_DUE)
        ->and(data_get($package->fresh()->metadata, 'recurrence.auto_payment.last_status'))->toBe('skipped')
        ->and(data_get($package->fresh()->metadata, 'recurrence.auto_payment.last_reason'))->toBe('no_stripe_customer')
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_auto_payment_skipped')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('sends configured client payment reminders for unpaid recurring renewal invoices', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $portalUser = customerPackagesPhaseFiveClient();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => 'renewal-reminder@example.com',
    ]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product, [
        'metadata' => [
            'recurrence' => [
                'payment_grace_days' => 7,
                'payment_reminder_days' => [0, 2],
            ],
        ],
    ]);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-01', 'UTC'));
    $invoice = Invoice::query()->firstOrFail();

    expect($summary['client_payment_reminders'])->toBe(1)
        ->and(data_get($package->fresh()->metadata, 'automation.notifications.client_payment_due_reminders.invoice_'.$invoice->id.'.day_0.sent_at'))->not->toBeNull();

    Notification::assertSentTo($customer, ActionEmailNotification::class);
    Notification::assertSentTo($portalUser, CustomerPackageBillingNotification::class);

    Notification::fake();
    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-02', 'UTC'));

    expect($summary['client_payment_reminders'])->toBe(0);
    Notification::assertNotSentTo($customer, ActionEmailNotification::class);
    Notification::assertNotSentTo($portalUser, CustomerPackageBillingNotification::class);

    Notification::fake();
    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-03', 'UTC'));

    expect($summary['client_payment_reminders'])->toBe(1)
        ->and(data_get($package->fresh()->metadata, 'automation.notifications.client_payment_due_reminders.invoice_'.$invoice->id.'.day_2.sent_at'))->not->toBeNull();

    Notification::assertSentTo($customer, ActionEmailNotification::class);
    Notification::assertSentTo($portalUser, CustomerPackageBillingNotification::class);

    Carbon::setTestNow();
});

it('respects client billing preferences and email opt-outs for renewal payment reminders', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-01 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner([
        'company_notification_settings' => [
            'alerts' => [
                'billing' => [
                    'email' => false,
                    'sms' => false,
                    'whatsapp' => false,
                ],
            ],
        ],
    ]);
    $portalUser = customerPackagesPhaseFiveClient([
        'notification_settings' => [
            'channels' => ['in_app' => true, 'push' => true],
            'categories' => ['billing' => false],
        ],
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => 'blocked-renewal@example.com',
    ]);
    CustomerOptOut::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'destination' => $customer->email,
        'destination_hash' => CampaignRecipient::destinationHash($customer->email),
        'source' => 'test',
        'opted_out_at' => now(),
    ]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product, [
        'metadata' => [
            'recurrence' => [
                'payment_grace_days' => 7,
                'payment_reminder_days' => [0],
            ],
        ],
    ]);

    app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-01', 'UTC'));

    expect($summary['client_payment_reminders'])->toBe(0);
    Notification::assertNotSentTo($customer, ActionEmailNotification::class);
    Notification::assertNotSentTo($portalUser, CustomerPackageBillingNotification::class);

    Carbon::setTestNow();
});

it('notifies the client when a recurring forfait is suspended and when it resumes after payment', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-04 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner([
        'payment_methods' => ['card'],
        'default_payment_method' => 'card',
    ]);
    $portalUser = customerPackagesPhaseFiveClient();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => 'suspended-client@example.com',
    ]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product, [
        'metadata' => [
            'recurrence' => [
                'payment_grace_days' => 2,
                'payment_reminder_days' => [10],
            ],
        ],
    ]);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);
    $invoice = app(CustomerPackageService::class)->createRenewalInvoice($owner, $customer, $package);

    $summary = app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-04', 'UTC'));

    expect($summary['suspended_renewals'])->toBe(1)
        ->and($summary['client_suspension_notices'])->toBe(1)
        ->and(data_get($package->fresh()->metadata, 'automation.notifications.client_suspension_notices.invoice_'.$invoice->id.'.suspended.sent_at'))->not->toBeNull();

    Notification::assertSentTo($customer, ActionEmailNotification::class, fn (ActionEmailNotification $notification): bool => in_array($notification->title, ['Forfait suspendu', 'Forfait suspended'], true));
    Notification::assertSentTo($portalUser, CustomerPackageBillingNotification::class);

    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-05 08:00:00', 'UTC'));

    $this->actingAs($owner)
        ->postJson(route('payment.store', $invoice), [
            'amount' => 360,
            'method' => 'card',
        ])
        ->assertCreated()
        ->assertJsonPath('invoice.status', 'paid');

    Notification::assertSentTo($customer, ActionEmailNotification::class, fn (ActionEmailNotification $notification): bool => in_array($notification->title, ['Forfait repris', 'Forfait resumed'], true));
    Notification::assertSentTo($portalUser, CustomerPackageBillingNotification::class);
    expect(ActivityLog::query()->where('action', 'customer_package_renewal_payment_received')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('cancels a recurring forfait immediately with a mandatory reason and voids its pending renewal invoice', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-02 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);
    $invoice = app(CustomerPackageService::class)->createRenewalInvoice($owner, $customer, $package);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.cancel-recurring', [$customer, $package]), [
            'mode' => 'immediate',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.cancel-recurring', [$customer, $package]), [
            'mode' => 'immediate',
            'reason' => 'Client requested cancellation by phone.',
        ])
        ->assertOk()
        ->assertJsonPath('customerPackage.status', CustomerPackage::STATUS_CANCELLED)
        ->assertJsonPath('customerPackage.recurrence_status', CustomerPackage::RECURRENCE_CANCELLED);

    $package->refresh();

    expect($package->status)->toBe(CustomerPackage::STATUS_CANCELLED)
        ->and($package->recurrence_status)->toBe(CustomerPackage::RECURRENCE_CANCELLED)
        ->and($package->cancelled_at)->not->toBeNull()
        ->and($package->next_renewal_at)->toBeNull()
        ->and($invoice->fresh()->status)->toBe('void')
        ->and(data_get($package->metadata, 'recurrence.cancellation_reason'))->toBe('Client requested cancellation by phone.')
        ->and(data_get($package->metadata, 'recurrence.pending_invoice_status'))->toBe('void')
        ->and(ActivityLog::query()->where('action', 'customer_package_recurring_cancelled_immediately')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('schedules a recurring forfait cancellation at period end without creating a renewal invoice', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.cancel-recurring', [$customer, $package]), [
            'mode' => 'end_of_period',
            'reason' => 'Client will stop after current month.',
        ])
        ->assertOk()
        ->assertJsonPath('customerPackage.status', CustomerPackage::STATUS_ACTIVE)
        ->assertJsonPath('customerPackage.recurrence_status', CustomerPackage::RECURRENCE_CANCELLED);

    $package->refresh();

    expect($package->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($package->recurrence_status)->toBe(CustomerPackage::RECURRENCE_CANCELLED)
        ->and($package->cancelled_at)->toBeNull()
        ->and($package->next_renewal_at)->toBeNull()
        ->and(data_get($package->metadata, 'recurrence.cancel_at_period_end'))->toBeTrue()
        ->and(data_get($package->metadata, 'recurrence.cancellation_effective_at'))->toBe('2026-05-31');

    app(CustomerPackageAutomationService::class)->process(Carbon::parse('2026-06-01', 'UTC'));

    expect(Invoice::query()->count())->toBe(0)
        ->and($package->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and(ActivityLog::query()->where('action', 'customer_package_recurring_cancellation_scheduled')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('upgrades a recurring forfait to another recurring offer without automatic prorata', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);
    $targetOffer = customerPackagesPhaseFiveOffer($owner, $product, [
        'name' => 'Recurring premium forfait',
        'price' => 520,
        'included_quantity' => 6,
    ]);

    $packageService = app(CustomerPackageService::class);
    $package = $packageService->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);
    $packageService->consume($owner, $customer, $package, [
        'quantity' => 2,
        'used_at' => '2026-05-10',
        'source' => 'test_usage',
    ]);

    $this->actingAs($owner)
        ->postJson(route('customer.packages.change-recurring-offer', [$customer, $package]), [
            'target_offer_package_id' => $targetOffer->id,
            'change_type' => 'upgrade',
            'starts_at' => '2026-06-01',
            'carry_over_unused_balance' => true,
            'note' => 'Upgrade to premium next month.',
        ])
        ->assertCreated()
        ->assertJsonPath('customerPackage.offer_package_id', $targetOffer->id)
        ->assertJsonPath('customerPackage.initial_quantity', 8)
        ->assertJsonPath('customerPackage.starts_at', '2026-06-01T00:00:00.000000Z');

    $changed = CustomerPackage::query()
        ->where('renewed_from_customer_package_id', $package->id)
        ->where('offer_package_id', $targetOffer->id)
        ->firstOrFail();
    $package->refresh();

    expect($package->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($package->recurrence_status)->toBe(CustomerPackage::RECURRENCE_CANCELLED)
        ->and($package->expires_at->toDateString())->toBe('2026-05-31')
        ->and($package->next_renewal_at)->toBeNull()
        ->and(data_get($package->metadata, 'recurrence.changed_to_customer_package_id'))->toBe($changed->id)
        ->and(data_get($package->metadata, 'recurrence.change_type'))->toBe('upgrade')
        ->and($changed->initial_quantity)->toBe(8)
        ->and($changed->remaining_quantity)->toBe(8)
        ->and(data_get($changed->metadata, 'recurrence.period_allocation_quantity'))->toBe(6)
        ->and(data_get($changed->metadata, 'recurrence.carried_over_quantity'))->toBe(2)
        ->and(ActivityLog::query()->where('action', 'customer_package_recurring_upgrade')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('suspends payment due recurring forfaits after the grace period', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-09 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
    ]);
    app(CustomerPackageService::class)->createRenewalInvoice($owner, $customer, $package);

    $this->artisan('offer-packages:automation --date=2026-06-09')
        ->expectsOutput('Offer package automation: expired 0, low balance alerts 0, marketing reminders 0, renewal reminders 0, renewal invoices 0, paid renewals 0, suspended renewals 1.')
        ->assertExitCode(0);

    $package->refresh();

    expect($package->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($package->recurrence_status)->toBe(CustomerPackage::RECURRENCE_SUSPENDED)
        ->and(data_get($package->metadata, 'recurrence.suspension_reason'))->toBe('renewal_payment_overdue')
        ->and(ActivityLog::query()->where('action', 'customer_package_renewal_suspended')->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('records marketing behavior events for forfait retention triggers', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-05-11 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $product = customerPackagesPhaseFiveProduct($owner);
    $oneShotOffer = customerPackagesPhaseFiveOffer($owner, $product, [
        'name' => 'One shot forfait',
        'is_recurring' => false,
        'recurrence_frequency' => null,
        'validity_days' => 30,
    ]);
    $recurringOffer = customerPackagesPhaseFiveOffer($owner, $product, [
        'name' => 'Retention monthly forfait',
    ]);
    $packageService = app(CustomerPackageService::class);

    $packageService->assign($owner, $customer, $oneShotOffer, [
        'starts_at' => '2026-05-01',
        'initial_quantity' => 5,
    ]);

    $expired = $packageService->assign($owner, $customer, $oneShotOffer, [
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-05-10',
        'initial_quantity' => 5,
    ]);
    $lowBalance = $packageService->assign($owner, $customer, $oneShotOffer, [
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-06-30',
        'initial_quantity' => 5,
    ]);
    $lowBalance->forceFill([
        'consumed_quantity' => 4,
        'remaining_quantity' => 1,
    ])->save();
    $packageService->assign($owner, $customer, $oneShotOffer, [
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-05-15',
        'initial_quantity' => 5,
    ]);

    $this->artisan('offer-packages:automation --date=2026-05-11')->assertExitCode(0);

    $renewable = $packageService->assign($owner, $customer, $recurringOffer, [
        'starts_at' => '2026-05-01',
    ]);
    $packageService->renew($owner, $customer, $renewable, [
        'starts_at' => '2026-06-01',
        'initial_quantity' => 4,
    ]);

    $suspended = $packageService->assign($owner, $customer, $recurringOffer, [
        'starts_at' => '2026-05-01',
    ]);
    $packageService->createRenewalInvoice($owner, $customer, $suspended);
    Carbon::setTestNow(Carbon::parse('2026-06-09 08:00:00', 'UTC'));

    $this->artisan('offer-packages:automation --date=2026-06-09')->assertExitCode(0);

    $eventTypes = CustomerBehaviorEvent::query()
        ->where('user_id', $owner->id)
        ->where('customer_id', $customer->id)
        ->pluck('event_type')
        ->all();

    expect($eventTypes)->toContain(
        'customer_package_purchased',
        'customer_package_low_balance',
        'customer_package_expiring_soon',
        'customer_package_expired',
        'customer_package_renewed',
        'customer_package_suspended'
    );

    $lowBalanceEvent = CustomerBehaviorEvent::query()
        ->where('event_type', 'customer_package_low_balance')
        ->firstOrFail();

    expect($expired->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and($suspended->fresh()->recurrence_status)->toBe(CustomerPackage::RECURRENCE_SUSPENDED)
        ->and(data_get($lowBalanceEvent->metadata, 'customer_package_id'))->toBe($lowBalance->id)
        ->and(data_get($lowBalanceEvent->metadata, 'remaining_quantity'))->toBe(1)
        ->and($lowBalanceEvent->product_id)->toBe($product->id);

    Carbon::setTestNow();
});

it('segments customers from forfait state and behavior events', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-12 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFiveOwner();
    $otherOwner = customerPackagesPhaseFiveOwner();
    $product = customerPackagesPhaseFiveProduct($owner);
    $offer = customerPackagesPhaseFiveOffer($owner, $product);

    $matching = Customer::factory()->create(['user_id' => $owner->id]);
    $suspended = Customer::factory()->create(['user_id' => $owner->id]);
    $outsideBalance = Customer::factory()->create(['user_id' => $owner->id]);
    $otherTenant = Customer::factory()->create(['user_id' => $otherOwner->id]);

    CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $matching->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-05-01',
        'expires_at' => '2026-05-15',
        'initial_quantity' => 5,
        'remaining_quantity' => 1,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 360,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        'current_period_starts_at' => '2026-05-01',
        'current_period_ends_at' => '2026-05-31',
        'next_renewal_at' => '2026-06-01',
    ]);

    CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $suspended->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-05-01',
        'expires_at' => '2026-05-31',
        'initial_quantity' => 5,
        'remaining_quantity' => 3,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 360,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_SUSPENDED,
        'current_period_starts_at' => '2026-05-01',
        'current_period_ends_at' => '2026-05-31',
        'next_renewal_at' => '2026-06-01',
    ]);

    CustomerPackage::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $outsideBalance->id,
        'offer_package_id' => $offer->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-05-01',
        'expires_at' => '2026-06-30',
        'initial_quantity' => 5,
        'remaining_quantity' => 4,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 360,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        'current_period_starts_at' => '2026-05-01',
        'current_period_ends_at' => '2026-05-31',
        'next_renewal_at' => '2026-06-01',
    ]);

    CustomerPackage::query()->create([
        'user_id' => $otherOwner->id,
        'customer_id' => $otherTenant->id,
        'status' => CustomerPackage::STATUS_ACTIVE,
        'starts_at' => '2026-05-01',
        'expires_at' => '2026-05-15',
        'initial_quantity' => 5,
        'remaining_quantity' => 1,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'price_paid' => 360,
        'currency_code' => 'CAD',
        'is_recurring' => true,
        'recurrence_frequency' => OfferPackage::RECURRENCE_MONTHLY,
        'recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        'current_period_starts_at' => '2026-05-01',
        'current_period_ends_at' => '2026-05-31',
        'next_renewal_at' => '2026-06-01',
    ]);

    CustomerBehaviorEvent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $matching->id,
        'event_type' => 'customer_package_low_balance',
        'occurred_at' => now(),
        'metadata' => ['source' => 'test'],
    ]);
    CustomerBehaviorEvent::query()->create([
        'user_id' => $otherOwner->id,
        'customer_id' => $otherTenant->id,
        'event_type' => 'customer_package_low_balance',
        'occurred_at' => now(),
        'metadata' => ['source' => 'test'],
    ]);

    $segment = SavedSegment::query()->create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Retention forfaits',
        'filters' => [
            'has_active_package' => true,
            'package_remaining_lte' => 2,
            'package_expires_within_days' => 7,
            'package_is_recurring' => true,
            'package_recurrence_status' => CustomerPackage::RECURRENCE_ACTIVE,
        ],
    ]);

    $resolved = app(SegmentResolverRegistry::class)->resolve($segment);

    expect($resolved['ids'])->toBe([$matching->id])
        ->and($resolved['selected_count'])->toBe(1);

    foreach ([$matching, $suspended, $outsideBalance] as $customer) {
        CustomerConsent::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'channel' => Campaign::CHANNEL_EMAIL,
            'status' => CustomerConsent::STATUS_GRANTED,
            'granted_at' => now(),
        ]);
    }

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Forfait retention',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Forfait',
        'body_template' => 'Forfait',
    ]);
    $campaign->audience()->create([
        'smart_filters' => [
            'operator' => 'AND',
            'rules' => [
                [
                    'field' => 'behavior_event',
                    'operator' => 'equals',
                    'value' => [
                        'event_type' => 'customer_package_low_balance',
                        'days' => 30,
                    ],
                ],
                [
                    'field' => 'package_remaining_quantity',
                    'operator' => 'lte',
                    'value' => 2,
                ],
            ],
        ],
        'manual_contacts' => [],
    ]);

    $eligibleCustomerIds = collect(app(AudienceResolver::class)->resolveForCampaign($campaign->fresh())['eligible'])
        ->pluck('customer_id')
        ->unique()
        ->values()
        ->all();

    expect($eligibleCustomerIds)->toBe([$matching->id]);

    $suspendedCampaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Forfait suspended',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);
    $suspendedCampaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Forfait',
        'body_template' => 'Forfait',
    ]);
    $suspendedCampaign->audience()->create([
        'smart_filters' => [
            'operator' => 'AND',
            'rules' => [
                [
                    'field' => 'package_recurrence_status',
                    'operator' => 'equals',
                    'value' => CustomerPackage::RECURRENCE_SUSPENDED,
                ],
            ],
        ],
        'manual_contacts' => [],
    ]);

    $suspendedEligibleIds = collect(app(AudienceResolver::class)->resolveForCampaign($suspendedCampaign->fresh())['eligible'])
        ->pluck('customer_id')
        ->unique()
        ->values()
        ->all();

    expect($suspendedEligibleIds)->toBe([$suspended->id]);

    Carbon::setTestNow();
});
