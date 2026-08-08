<?php

use App\Http\Middleware\EnsureDemoWorkspaceNotExpired;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\PublicBookingLink;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationQueuePaymentAttempt;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\TeamMember;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiKnowledgeItem;
use App\Services\Demo\DemoWorkspaceCatalog;
use App\Services\Demo\DemoWorkspaceProvisioner;
use App\Services\QueueInvoiceReceiptService;
use App\Services\ReservationQueueInvoiceService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Session\Session as SessionContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function salonEclatDatasetAdmin(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin'],
        ['description' => 'Platform admin role']
    );

    return User::query()->create([
        'name' => 'Salon Eclat Demo Admin',
        'email' => 'salon-eclat-demo-admin@example.test',
        'password' => Hash::make('password'),
        'role_id' => $role->id,
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function salonEclatDatasetPayload(): array
{
    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'salon_eclat_complete');

    return array_replace($catalog->defaults(), $preset, [
        'selected_modules' => $preset['modules'],
        'scenario_packs' => $preset['scenario_packs'],
        'branding_profile' => array_replace($preset['branding_profile'], [
            'name' => 'Salon Éclat',
            'tagline' => 'La beauté, orchestrée avec attention.',
            'description' => 'Salon de coiffure et barbier — coupe, couleur, soin.',
            'contact_email' => 'bonjour@salon-eclat.example.test',
            'phone' => '+1 514 555 0188',
        ]),
        'internal_notes' => 'Dataset immersif automatisé Salon Éclat.',
        'prefill_source' => 'preset',
        'prefill_payload' => ['preset' => 'salon_eclat_complete'],
        'expires_at' => now()->addDays(14)->toDateString(),
    ]);
}

it('keeps the lean salon demo provisionable', function () {
    Storage::fake('public');

    /** @var DemoWorkspaceCatalog $catalog */
    $catalog = app(DemoWorkspaceCatalog::class);
    $preset = collect($catalog->presets())->firstWhere('key', 'salon_queue');
    $workspace = app(DemoWorkspaceProvisioner::class)->create(
        array_replace($catalog->defaults(), $preset, [
            'prospect_name' => 'Lean Salon Prospect',
            'prospect_email' => 'lean-salon@example.test',
            'prospect_company' => 'Lean Salon',
            'company_name' => 'Lean Salon',
            'selected_modules' => $preset['modules'],
            'scenario_packs' => $preset['scenario_packs'],
            'branding_profile' => $preset['branding_profile'],
            'expires_at' => now()->addDays(7)->toDateString(),
        ]),
        salonEclatDatasetAdmin()
    );

    expect($workspace->provisioning_status)->toBe('ready')
        ->and($workspace->seed_summary['completed_checkouts'] ?? null)->toBe(0)
        ->and($workspace->seed_summary['checkout_invoices'] ?? null)->toBe(0)
        ->and($workspace->seed_summary['checkout_payments'] ?? null)->toBe(0);

    $partialPayment = Payment::query()
        ->where('user_id', $workspace->owner_user_id)
        ->whereHas('invoice', fn ($query) => $query->where('status', 'partial'))
        ->firstOrFail();

    expect((float) $partialPayment->charged_total)->toBe((float) $partialPayment->amount)
        ->and($partialPayment->method)->toBe('card')
        ->and($partialPayment->provider)->toBe('demo');
});

it('provisions the complete narrative Salon Eclat dataset', function () {
    Storage::fake('public');
    Mail::fake();
    Notification::fake();

    $workspace = app(DemoWorkspaceProvisioner::class)->create(
        salonEclatDatasetPayload(),
        salonEclatDatasetAdmin()
    );
    $owner = $workspace->owner()->firstOrFail();

    expect($owner->name)->toBe('Amina Diallo')
        ->and($owner->company_name)->toBe('Salon Éclat')
        ->and($workspace->seed_profile)->toBe('immersive')
        ->and($workspace->seed_summary['team_members'] ?? null)->toBe(3)
        ->and($workspace->seed_summary['services'] ?? null)->toBe(10)
        ->and($workspace->seed_summary['products'] ?? null)->toBe(5)
        ->and($workspace->seed_summary['offer_packages'] ?? null)->toBe(3)
        ->and($workspace->seed_summary['customer_packages'] ?? null)->toBe(3)
        ->and($workspace->seed_summary['promotions'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['assistant_knowledge_items'] ?? null)->toBe(4)
        ->and($workspace->seed_summary['social_posts'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['public_booking_links'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['completed_checkouts'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['checkout_invoices'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['checkout_payments'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['accounting_entries'] ?? 0)->toBeGreaterThan(0)
        ->and($workspace->seed_summary['accounting_batches'] ?? 0)->toBeGreaterThan(0)
        ->and($workspace->seed_summary['client_portal_accounts'] ?? null)->toBe(1)
        ->and($workspace->seed_summary['client_portal_credentials'] ?? [])->toHaveCount(1);

    expect(collect($workspace->extra_access_credentials)->pluck('role_key')->all())
        ->toBe(['front_desk', 'staff'])
        ->and(collect($workspace->extra_access_credentials)->every(
            fn (array $credential): bool => (bool) ($credential['is_active'] ?? false)
        ))->toBeTrue();

    expect(TeamMember::query()
        ->where('account_id', $owner->id)
        ->with('user')
        ->get()
        ->pluck('user.name')
        ->sort()
        ->values()
        ->all())->toBe(collect(['Sophie Tremblay', 'Karim Benali', 'Léa Moreau'])->sort()->values()->all());

    Reservation::query()->forAccount($owner->id)->get()->each(function (Reservation $reservation): void {
        $startsAt = $reservation->starts_at->copy()->setTimezone('America/Toronto');
        $endsAt = $reservation->ends_at->copy()->setTimezone('America/Toronto');

        expect($startsAt->dayOfWeekIso)->toBeGreaterThanOrEqual(2)
            ->toBeLessThanOrEqual(6)
            ->and($startsAt->hour)->toBeGreaterThanOrEqual(9)
            ->and($endsAt->hour)->toBeLessThanOrEqual(17);
    });

    expect(ProductCategory::query()->where('user_id', $owner->id)->pluck('name')->all())
        ->toContain('Coupe', 'Coloration', 'Coiffage', 'Soin capillaire', 'Barbier', 'Produits capillaires');
    expect(Product::query()->where('user_id', $owner->id)->where('item_type', Product::ITEM_TYPE_SERVICE)->count())->toBe(10)
        ->and(Product::query()->where('user_id', $owner->id)->where('name', 'Balayage complet')->value('price'))->toBe('210.00')
        ->and(Product::query()->where('user_id', $owner->id)->where('name', 'Shampoing réparateur 250 ml')->exists())->toBeTrue();

    $marie = Customer::query()->where('user_id', $owner->id)->where('first_name', 'Marie')->where('last_name', 'Lefebvre')->firstOrFail();
    $thomas = Customer::query()->where('user_id', $owner->id)->where('first_name', 'Thomas')->where('last_name', 'Roy')->firstOrFail();
    $mariePackage = CustomerPackage::query()->where('customer_id', $marie->id)->firstOrFail();
    $thomasPackage = CustomerPackage::query()->where('customer_id', $thomas->id)->firstOrFail();

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(20)
        ->and(Customer::query()
            ->where('user_id', $owner->id)
            ->whereNotIn('salutation', ['Mr', 'Mrs', 'Miss'])
            ->exists())->toBeFalse()
        ->and($marie->salutation)->toBe('Mrs')
        ->and($thomas->salutation)->toBe('Mr')
        ->and($mariePackage->offerPackage?->name)->toBe('Carte 10 brushings')
        ->and($mariePackage->remaining_quantity)->toBe(7)
        ->and($mariePackage->usages()->count())->toBe(3)
        ->and($thomasPackage->offerPackage?->name)->toBe('Abonnement Barbe')
        ->and($thomasPackage->is_recurring)->toBeTrue()
        ->and($marie->portal_access)->toBeTrue()
        ->and(Hash::check('password', $marie->portalUser?->password ?? ''))->toBeTrue();

    expect(OfferPackage::query()->where('user_id', $owner->id)->count())->toBe(3)
        ->and(Promotion::query()->where('user_id', $owner->id)->value('code'))->toBe('RENTREE20')
        ->and(Campaign::query()->where('user_id', $owner->id)->value('campaign_type'))->toBe(Campaign::TYPE_WINBACK)
        ->and(Campaign::query()->where('user_id', $owner->id)->value('status'))->toBe(Campaign::STATUS_DRAFT)
        ->and(AiAssistantSetting::query()->where('tenant_id', $owner->id)->value('enabled'))->toBeTrue()
        ->and(AiKnowledgeItem::query()->where('tenant_id', $owner->id)->count())->toBe(4)
        ->and(SocialPost::query()->where('user_id', $owner->id)->value('status'))->toBe(SocialPost::STATUS_SCHEDULED);

    $bookingLink = PublicBookingLink::query()->where('account_id', $owner->id)->firstOrFail();
    $publicBookingUrl = $bookingLink->publicUrl($owner);
    expect($bookingLink->slug)->toBe('rendez-vous')
        ->and($bookingLink->requires_deposit)->toBeFalse()
        ->and($bookingLink->services()->count())->toBe(10)
        ->and(Campaign::query()->where('user_id', $owner->id)->value('cta_url'))->toBe($publicBookingUrl)
        ->and(SocialPost::query()->where('user_id', $owner->id)->value('link_url'))->toBe($publicBookingUrl);

    $karim = TeamMember::query()
        ->where('account_id', $owner->id)
        ->whereHas('user', fn ($query) => $query->where('name', 'Karim Benali'))
        ->firstOrFail();
    $ticket = ReservationQueueItem::query()
        ->forAccount($owner->id)
        ->where('queue_number', 'SAL-ECLAT-PAID-001')
        ->with('reservation')
        ->firstOrFail();
    $invoice = Invoice::query()
        ->where('reservation_queue_item_id', $ticket->id)
        ->with(['items', 'payments.tipAllocations'])
        ->sole();
    $payment = $invoice->payments->sole();

    expect($ticket->status)->toBe(ReservationQueueItem::STATUS_DONE)
        ->and($ticket->reservation?->status)->toBe('completed')
        ->and((float) data_get($ticket->metadata, 'checkout.subtotal'))->toBe(65.0)
        ->and((float) data_get($ticket->metadata, 'checkout.tax_rate'))->toBe(14.975)
        ->and((float) data_get($ticket->metadata, 'checkout.tax_total'))->toBe(9.73)
        ->and((float) data_get($ticket->metadata, 'checkout.invoice_total'))->toBe(74.73)
        ->and($invoice->source)->toBe(ReservationQueueInvoiceService::SOURCE_RESERVATION_QUEUE)
        ->and((float) $invoice->subtotal)->toBe(65.0)
        ->and((float) $invoice->tax_total)->toBe(9.73)
        ->and((float) $invoice->total)->toBe(74.73)
        ->and($invoice->status)->toBe('paid')
        ->and((float) $invoice->amount_paid)->toBe(74.73)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and((int) data_get($invoice->billing_snapshot, 'version'))->toBe(1)
        ->and($invoice->items)->toHaveCount(1)
        ->and($payment->provider)->toBe('manual')
        ->and($payment->method)->toBe('cash')
        ->and($payment->status)->toBe(Payment::STATUS_COMPLETED)
        ->and((float) $payment->amount)->toBe(74.73)
        ->and((float) $payment->tip_base_amount)->toBe(65.0)
        ->and((float) $payment->tip_percent)->toBe(18.0)
        ->and((float) $payment->tip_amount)->toBe(11.70)
        ->and((float) $payment->charged_total)->toBe(86.43)
        ->and($payment->tip_assignee_user_id)->toBe($karim->user_id)
        ->and((float) $payment->tipAllocations->sum('amount'))->toBe(11.70)
        ->and($invoice->receipt_delivery)->toBeNull()
        ->and($invoice->receipt_delivery_status)->toBeNull();

    expect(CustomerPackageUsage::query()
        ->where('customer_package_id', $mariePackage->id)
        ->where('reservation_id', $ticket->reservation_id)
        ->exists())->toBeFalse()
        ->and(ReservationQueuePaymentAttempt::query()->where('account_id', $owner->id)->count())->toBe(0)
        ->and(Payment::query()->where('user_id', $owner->id)->where('provider', 'stripe')->exists())->toBeFalse()
        ->and(Payment::query()->where('user_id', $owner->id)->whereNotNull('provider_reference')->exists())->toBeFalse();

    $receiptRequest = Request::create(
        app(QueueInvoiceReceiptService::class)->receiptUrl($invoice),
        'GET'
    );
    $receiptResponse = app(HttpKernel::class)->handle($receiptRequest);
    expect($receiptResponse->getStatusCode())->toBe(200);
    Notification::assertNothingSent();
    Mail::assertNothingSent();

    $workspace->forceFill(['expires_at' => now()->subDay()])->save();
    $portalRequest = Request::create('/portal/invoices', 'GET');
    $portalRequest->setUserResolver(fn (): User => $marie->portalUser()->firstOrFail());
    $portalRequest->setLaravelSession(app(SessionContract::class));
    $portalResponse = app(EnsureDemoWorkspaceNotExpired::class)->handle(
        $portalRequest,
        fn () => response('allowed')
    );

    expect($portalResponse->getStatusCode())->toBe(302)
        ->and($portalResponse->headers->get('Location'))->toBe(route('demo.index'));
});

it('resets the immersive Salon Eclat dataset without duplicating or orphaning package data', function () {
    Storage::fake('public');
    Mail::fake();
    Notification::fake();

    $admin = salonEclatDatasetAdmin();
    $provisioner = app(DemoWorkspaceProvisioner::class);
    $workspace = $provisioner->create(salonEclatDatasetPayload(), $admin);
    $previousOwnerId = $workspace->owner_user_id;
    $previousPortalUserId = Customer::query()
        ->where('user_id', $previousOwnerId)
        ->whereNotNull('portal_user_id')
        ->value('portal_user_id');

    $workspace = $provisioner->resetToBaseline($workspace, $admin);
    $ownerId = $workspace->owner_user_id;

    expect($ownerId)->not->toBe($previousOwnerId)
        ->and(User::query()->whereKey($previousOwnerId)->exists())->toBeFalse()
        ->and(User::query()->whereKey($previousPortalUserId)->exists())->toBeFalse()
        ->and(OfferPackage::query()->where('user_id', $previousOwnerId)->exists())->toBeFalse()
        ->and(CustomerPackage::query()->where('user_id', $previousOwnerId)->exists())->toBeFalse()
        ->and(CustomerPackageUsage::query()->where('user_id', $previousOwnerId)->exists())->toBeFalse()
        ->and(PublicBookingLink::query()->where('account_id', $previousOwnerId)->exists())->toBeFalse()
        ->and(TeamMember::query()->where('account_id', $ownerId)->count())->toBe(3)
        ->and(Product::query()->where('user_id', $ownerId)->where('item_type', Product::ITEM_TYPE_SERVICE)->count())->toBe(10)
        ->and(OfferPackage::query()->where('user_id', $ownerId)->count())->toBe(3)
        ->and(CustomerPackage::query()->where('user_id', $ownerId)->count())->toBe(3)
        ->and(PublicBookingLink::query()->where('account_id', $ownerId)->count())->toBe(1)
        ->and(ReservationQueueItem::query()->forAccount($ownerId)->where('queue_number', 'SAL-ECLAT-PAID-001')->value('status'))->toBe(ReservationQueueItem::STATUS_DONE)
        ->and(SocialPost::query()->where('user_id', $ownerId)->count())->toBe(1);

    Notification::assertNothingSent();
    Mail::assertNothingSent();
});
