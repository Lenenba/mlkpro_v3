<?php

use App\Exceptions\StripeQueueCheckoutVerificationException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ReservationQueueItem;
use App\Models\ReservationQueuePaymentAttempt;
use App\Models\ReservationSetting;
use App\Models\User;
use App\Services\ReservationQueueInvoiceService;
use App\Services\StripeInvoiceService;
use Illuminate\Support\Facades\Notification;
use Stripe\StripeClient;

final class QueueStripeCheckoutTestObject
{
    public function __construct(private readonly array $attributes) {}

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}

final class QueueStripeCheckoutTestSessions
{
    public int $createCalls = 0;

    public ?RuntimeException $createException = null;

    public array $payloads = [];

    public array $options = [];

    public array $sessions = [];

    public function create(array $payload, array $options = []): object
    {
        $this->createCalls++;
        if ($this->createException) {
            throw $this->createException;
        }
        $sessionId = 'cs_queue_test_'.$this->createCalls;
        $amountTotal = (int) collect($payload['line_items'])
            ->sum(fn (array $item): int => (int) $item['price_data']['unit_amount'] * (int) $item['quantity']);
        $session = [
            'id' => $sessionId,
            'mode' => $payload['mode'],
            'status' => 'open',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'client_reference_id' => $payload['client_reference_id'],
            'metadata' => $payload['metadata'],
            'amount_total' => $amountTotal,
            'currency' => $payload['line_items'][0]['price_data']['currency'],
            'expires_at' => now('UTC')->addHours(23)->getTimestamp(),
            'url' => 'https://checkout.stripe.test/'.$sessionId,
        ];

        $this->payloads[] = $payload;
        $this->options[] = $options;
        $this->sessions[$sessionId] = $session;

        return new QueueStripeCheckoutTestObject($session);
    }

    public function retrieve(string $sessionId, array $params = [], array $options = []): object
    {
        if (! isset($this->sessions[$sessionId])) {
            throw new RuntimeException('Unknown test Stripe session.');
        }

        return new QueueStripeCheckoutTestObject($this->sessions[$sessionId]);
    }

    public function expire(string $sessionId, array $params = [], array $options = []): object
    {
        if (($this->sessions[$sessionId]['payment_status'] ?? null) === 'paid') {
            throw new RuntimeException('A paid Stripe session cannot be expired.');
        }

        $this->sessions[$sessionId]['status'] = 'expired';

        return new QueueStripeCheckoutTestObject($this->sessions[$sessionId]);
    }

    public function markPaid(string $sessionId, array $overrides = []): array
    {
        $this->sessions[$sessionId] = array_replace($this->sessions[$sessionId], [
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_'.$sessionId,
        ], $overrides);

        return $this->sessions[$sessionId];
    }
}

final class QueueStripeCheckoutTestClient extends StripeClient
{
    public function __construct(private readonly QueueStripeCheckoutTestSessions $sessions)
    {
        parent::__construct('sk_test_queue_checkout');
    }

    public function getService($name)
    {
        return match ($name) {
            'checkout' => (object) ['sessions' => $this->sessions],
            default => throw new RuntimeException("Unexpected Stripe service [{$name}] in queue checkout test."),
        };
    }
}

function queueStripeCheckoutFixture(float $invoiceTotal = 40.00): array
{
    $owner = User::factory()->create([
        'company_features' => ['reservations' => true],
        'payment_methods' => ['card', 'cash'],
        'default_payment_method' => 'card',
        'currency_code' => 'CAD',
    ]);
    ReservationSetting::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_assignment_mode' => 'shared',
        'queue_dispatch_mode' => 'fifo',
        'queue_grace_minutes' => 5,
        'queue_no_show_on_grace_expiry' => false,
    ]);
    $category = ProductCategory::query()->create([
        'name' => 'Stripe queue services',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $service = Product::query()->create([
        'name' => 'Stripe queue service',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'tracking_type' => 'none',
        'price' => $invoiceTotal,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
    ]);
    $queueItem = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'service_id' => $service->id,
        'created_by_user_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'STRIPE-QUEUE-'.$owner->id,
        'status' => ReservationQueueItem::STATUS_AWAITING_PAYMENT,
        'estimated_duration_minutes' => 30,
        'finished_at' => now('UTC'),
        'metadata' => [
            'guest_name' => 'Stripe Queue Guest',
            'checkout' => [
                'service_name' => 'Stripe queue service',
                'base_amount' => $invoiceTotal,
                'subtotal' => $invoiceTotal,
                'tax_total' => 0,
                'invoice_total' => $invoiceTotal,
                'currency_code' => 'CAD',
            ],
        ],
    ]);
    $invoice = Invoice::query()->create([
        'reservation_queue_item_id' => $queueItem->id,
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'total' => $invoiceTotal,
        'currency_code' => 'CAD',
        'source' => ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE,
        'customer_snapshot' => [
            'type' => 'guest',
            'name' => 'Stripe Queue Guest',
            'queue_number' => $queueItem->queue_number,
        ],
    ]);

    return [$owner, $queueItem, $invoice];
}

function queueStripeCheckoutService(QueueStripeCheckoutTestSessions $sessions): StripeInvoiceService
{
    config()->set('services.stripe.enabled', true);
    config()->set('services.stripe.secret', 'sk_test_queue_checkout');
    config()->set('services.stripe.connect_enabled', false);

    $service = app(StripeInvoiceService::class);
    $reflection = new ReflectionProperty($service, 'client');
    $reflection->setAccessible(true);
    $reflection->setValue($service, new QueueStripeCheckoutTestClient($sessions));
    app()->instance(StripeInvoiceService::class, $service);

    return $service;
}

function queueStripeCheckoutAttempt(
    StripeInvoiceService $service,
    Invoice $invoice,
    ReservationQueueItem $queueItem,
    float $tipAmount = 5.00
): ReservationQueuePaymentAttempt {
    return $service->prepareQueueCheckoutAttempt($invoice, $queueItem, (float) $invoice->total, [
        'tip_amount' => $tipAmount,
        'tip_type' => $tipAmount > 0 ? 'fixed' : 'none',
        'tip_percent' => null,
        'tip_base_amount' => (float) $invoice->total,
        'charged_total' => (float) $invoice->total + $tipAmount,
        'tip_assignee_user_id' => null,
    ]);
}

beforeEach(function () {
    Notification::fake();
    config()->set('app.env', 'testing');
    config()->set('services.stripe.webhook_secret', null);
});

test('queue Stripe checkout reuses one persisted session and idempotency key on double click', function () {
    [, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);

    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $first = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    $sameAttempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $second = $service->startQueueCheckoutAttempt($sameAttempt, 'https://app.test/success', 'https://app.test/cancel');

    expect($sameAttempt->id)->toBe($attempt->id)
        ->and($second['id'])->toBe($first['id'])
        ->and($sessions->createCalls)->toBe(1)
        ->and($sessions->options[0]['idempotency_key'])->toBe($attempt->idempotency_key)
        ->and(ReservationQueuePaymentAttempt::query()->count())->toBe(1);
});

test('ambiguous Stripe connection failure stays active and safely reuses the same idempotency key on retry', function () {
    [, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $sessions->createException = new RuntimeException('Simulated Stripe connection failure.');
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $idempotencyKey = $attempt->idempotency_key;

    expect(fn () => $service->startQueueCheckoutAttempt(
        $attempt,
        'https://app.test/success',
        'https://app.test/cancel'
    ))->toThrow(RuntimeException::class);

    $ambiguous = $attempt->fresh();
    expect($ambiguous->status)->toBe(ReservationQueuePaymentAttempt::STATUS_PREPARING)
        ->and($ambiguous->active_key)->not->toBeNull();
    expect(fn () => $service->ensureNoActiveQueueCheckoutAttempt($queueItem))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $sessions->createException = null;
    $retry = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($retry, 'https://app.test/success', 'https://app.test/cancel');

    expect($retry->id)->toBe($attempt->id)
        ->and($retry->idempotency_key)->toBe($idempotencyKey)
        ->and($started['id'])->toBe('cs_queue_test_2')
        ->and(ReservationQueuePaymentAttempt::query()->count())->toBe(1);
});

test('manual payment recorded before Stripe preparation prevents a card session from being created', function () {
    [$owner, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'reservation_queue_item_id' => $queueItem->id,
        'user_id' => $owner->id,
        'amount' => $invoice->total,
        'currency_code' => 'CAD',
        'tip_amount' => 0,
        'tip_type' => 'none',
        'tip_base_amount' => $invoice->total,
        'charged_total' => $invoice->total,
        'method' => 'cash',
        'provider' => 'manual',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now('UTC'),
    ]);

    expect(fn () => queueStripeCheckoutAttempt($service, $invoice, $queueItem, 0))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect($sessions->createCalls)->toBe(0)
        ->and(ReservationQueuePaymentAttempt::query()->count())->toBe(0);
});

test('an active Stripe session blocks a manual payment even after its local expiry timestamp', function () {
    [, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    $attempt->forceFill(['expires_at' => now('UTC')->subMinute()])->save();
    $sessions->markPaid($started['id']);

    expect(fn () => $service->ensureNoActiveQueueCheckoutAttempt($queueItem))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $payment = $service->cancelQueueCheckoutAttempt($attempt);

    expect($payment)->not->toBeNull()
        ->and($queueItem->fresh()->status)->toBe(ReservationQueueItem::STATUS_DONE)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($attempt->fresh()->status)->toBe(ReservationQueuePaymentAttempt::STATUS_COMPLETED);
});

test('browser return reconciles a paid queue session before the webhook arrives', function () {
    [$owner, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    $sessions->markPaid($started['id']);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.queue.stripe.return', [
            'attempt' => $attempt->public_id,
            'session_id' => $started['id'],
        ]));

    $response->assertRedirectContains('stripe=success')
        ->assertSessionHas('success');
    expect(Payment::query()->where('reservation_queue_item_id', $queueItem->id)->count())->toBe(1)
        ->and($queueItem->fresh()->status)->toBe(ReservationQueueItem::STATUS_DONE)
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($attempt->fresh()->status)->toBe(ReservationQueuePaymentAttempt::STATUS_COMPLETED);
});

test('paid Stripe checkout still settles an existing ticket after queue settings are disabled', function () {
    [, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    ReservationSetting::query()
        ->where('account_id', $queueItem->account_id)
        ->update([
            'business_preset' => 'service_general',
            'queue_mode_enabled' => false,
        ]);
    $sessions->markPaid($started['id']);

    $payment = $service->reconcileQueueCheckoutAttempt($attempt, $started['id']);

    expect($payment)->not->toBeNull()
        ->and($queueItem->fresh()->status)->toBe(ReservationQueueItem::STATUS_DONE)
        ->and($invoice->fresh()->status)->toBe('paid');
});

test('queue Stripe polling returns pending then a paid invoice receipt', function () {
    [$owner, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.queue.stripe.status', $attempt))
        ->assertOk()
        ->assertJsonPath('state', 'pending')
        ->assertJsonPath('poll_after_ms', 2500);

    $sessions->markPaid($started['id']);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.queue.stripe.status', $attempt))
        ->assertOk()
        ->assertJsonPath('state', 'success')
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_DONE)
        ->assertJsonPath('invoice.status', 'paid')
        ->assertJsonPath('payment.charged_total', 45)
        ->assertJson(fn ($json) => $json->whereType('invoice.receipt_url', 'string')->etc());
});

test('duplicate queue Stripe webhooks remain idempotent and repair a divergent ticket state', function () {
    [, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    $session = $sessions->markPaid($started['id']);
    $event = [
        'id' => 'evt_queue_checkout_completed',
        'type' => 'checkout.session.completed',
        'data' => ['object' => $session],
    ];

    $this->postJson(route('api.stripe.webhook'), $event)
        ->assertOk()
        ->assertJsonPath('received', true);
    $queueItem->forceFill(['status' => ReservationQueueItem::STATUS_AWAITING_PAYMENT])->save();

    $this->postJson(route('api.stripe.webhook'), $event)
        ->assertOk()
        ->assertJsonPath('received', true);

    expect(Payment::query()->where('reservation_queue_item_id', $queueItem->id)->count())->toBe(1)
        ->and($queueItem->fresh()->status)->toBe(ReservationQueueItem::STATUS_DONE)
        ->and($invoice->fresh()->status)->toBe('paid');
});

test('authentic Stripe terminal Checkout events release unpaid queue attempts', function (
    string $eventType,
    string $sessionStatus,
    string $attemptStatus,
    string $pollState,
    int $pollStatus
) {
    [$owner, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    $sessions->sessions[$started['id']]['status'] = $sessionStatus;
    $event = [
        'id' => 'evt_queue_terminal_'.$attemptStatus,
        'type' => $eventType,
        'data' => ['object' => $sessions->sessions[$started['id']]],
    ];

    $this->postJson(route('api.stripe.webhook'), $event)
        ->assertOk()
        ->assertJsonPath('received', true);

    $closed = $attempt->fresh();
    expect($closed->status)->toBe($attemptStatus)
        ->and($closed->active_key)->toBeNull()
        ->and(Payment::query()->where('reservation_queue_item_id', $queueItem->id)->exists())->toBeFalse()
        ->and($queueItem->fresh()->status)->toBe(ReservationQueueItem::STATUS_AWAITING_PAYMENT);
    $service->ensureNoActiveQueueCheckoutAttempt($queueItem);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.queue.stripe.status', $attempt))
        ->assertStatus($pollStatus)
        ->assertJsonPath('state', $pollState);
})->with([
    'expired' => [
        'checkout.session.expired',
        'expired',
        ReservationQueuePaymentAttempt::STATUS_EXPIRED,
        'cancel',
        200,
    ],
    'async failed' => [
        'checkout.session.async_payment_failed',
        'complete',
        ReservationQueuePaymentAttempt::STATUS_FAILED,
        'error',
        422,
    ],
]);

test('queue Stripe reconciliation rejects mismatched amount currency and connected account', function (array $overrides, ?string $eventAccount) {
    [, $queueItem, $invoice] = queueStripeCheckoutFixture();
    $sessions = new QueueStripeCheckoutTestSessions;
    $service = queueStripeCheckoutService($sessions);
    $attempt = queueStripeCheckoutAttempt($service, $invoice, $queueItem);
    $started = $service->startQueueCheckoutAttempt($attempt, 'https://app.test/success', 'https://app.test/cancel');
    $session = $sessions->markPaid($started['id'], $overrides);

    expect(fn () => $service->recordPaymentFromCheckoutSession($session, $eventAccount, $attempt))
        ->toThrow(StripeQueueCheckoutVerificationException::class);
    expect(Payment::query()->where('reservation_queue_item_id', $queueItem->id)->exists())->toBeFalse()
        ->and($queueItem->fresh()->status)->toBe(ReservationQueueItem::STATUS_AWAITING_PAYMENT);
})->with([
    'amount' => [['amount_total' => 9999], null],
    'currency' => [['currency' => 'usd'], null],
    'connected account' => [[], 'acct_unexpected'],
]);

test('Stripe webhook fails closed outside local and testing when its signing secret is missing', function () {
    config()->set('app.env', 'production');
    config()->set('services.stripe.webhook_secret', null);

    $this->postJson(route('api.stripe.webhook'), [
        'id' => 'evt_unsigned_production',
        'type' => 'ping',
        'data' => ['object' => []],
    ])->assertStatus(503)
        ->assertJsonPath('error', 'Stripe webhook is not configured');
});

test('Stripe webhook explicitly accepts unsigned development payloads only in local or testing', function () {
    config()->set('app.env', 'local');
    config()->set('services.stripe.webhook_secret', null);

    $this->postJson(route('api.stripe.webhook'), [
        'id' => 'evt_unsigned_local',
        'type' => 'ping',
        'data' => ['object' => []],
    ])->assertOk()
        ->assertJsonPath('received', true);
});
