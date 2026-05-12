<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\CampaignInAppNotification;
use App\Services\OfferPackages\CustomerPackageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

function customerPackagesPhaseFourOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'company_features' => [
            'reservations' => true,
            'quotes' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
        ],
    ], $overrides));
}

function customerPackagesPhaseFourProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Phase 4 catalog',
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Reservation service',
        'description' => 'Service consumed from a forfait',
        'price' => 80,
        'currency_code' => 'CAD',
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ], $overrides));
}

function customerPackagesPhaseFourOffer(User $owner, Product $product, array $overrides = []): OfferPackage
{
    $offer = OfferPackage::query()->create(array_merge([
        'user_id' => $owner->id,
        'name' => 'Forfait reservations',
        'type' => OfferPackage::TYPE_FORFAIT,
        'status' => OfferPackage::STATUS_ACTIVE,
        'description' => 'Reservation balance',
        'price' => 300,
        'currency_code' => 'CAD',
        'validity_days' => 45,
        'included_quantity' => 5,
        'unit_type' => OfferPackage::UNIT_SESSION,
        'is_public' => true,
    ], $overrides));

    $offer->items()->create([
        'product_id' => $product->id,
        'item_type_snapshot' => $product->item_type,
        'name_snapshot' => $product->name,
        'description_snapshot' => $product->description,
        'quantity' => 5,
        'unit_price' => 80,
        'included' => true,
        'is_optional' => false,
        'sort_order' => 0,
    ]);

    return $offer->fresh('items');
}

function customerPackagesPhaseFourReservation(User $owner, Customer $customer, Product $service, array $overrides = []): Reservation
{
    $startsAt = Carbon::parse($overrides['starts_at'] ?? '2026-05-10 10:00:00', 'UTC');
    $teamMemberId = $overrides['team_member_id']
        ?? TeamMember::factory()->create(['account_id' => $owner->id])->id;

    return Reservation::query()->create(array_merge([
        'account_id' => $owner->id,
        'team_member_id' => $teamMemberId,
        'client_id' => $customer->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ], $overrides));
}

it('consumes a matching customer forfait when a reservation is completed', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00', 'UTC'));

    $owner = customerPackagesPhaseFourOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $service = customerPackagesPhaseFourProduct($owner);
    $offer = customerPackagesPhaseFourOffer($owner, $service);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
        'initial_quantity' => 5,
    ]);
    $reservation = customerPackagesPhaseFourReservation($owner, $customer, $service);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), [
            'status' => Reservation::STATUS_COMPLETED,
        ])
        ->assertOk();

    $package->refresh();
    $reservation->refresh();

    expect($package->remaining_quantity)->toBe(4)
        ->and($package->consumed_quantity)->toBe(1)
        ->and(data_get($reservation->metadata, 'customer_package.status'))->toBe('consumed')
        ->and(data_get($reservation->metadata, 'customer_package.customer_package_id'))->toBe($package->id);

    $usage = CustomerPackageUsage::query()->firstOrFail();
    expect($usage->reservation_id)->toBe($reservation->id)
        ->and($usage->product_id)->toBe($service->id)
        ->and(data_get($usage->metadata, 'source'))->toBe('reservation_completed');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), [
            'status' => Reservation::STATUS_COMPLETED,
        ])
        ->assertOk();

    expect($package->fresh()->remaining_quantity)->toBe(4)
        ->and(CustomerPackageUsage::query()->active()->count())->toBe(1);

    Carbon::setTestNow();
});

it('restores an automatic reservation consumption when completion is reversed', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-11 12:00:00', 'UTC'));

    $owner = customerPackagesPhaseFourOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $service = customerPackagesPhaseFourProduct($owner);
    $offer = customerPackagesPhaseFourOffer($owner, $service);

    $package = app(CustomerPackageService::class)->assign($owner, $customer, $offer, [
        'starts_at' => '2026-05-01',
        'initial_quantity' => 2,
    ]);
    $reservation = customerPackagesPhaseFourReservation($owner, $customer, $service);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), ['status' => Reservation::STATUS_COMPLETED])
        ->assertOk();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), ['status' => Reservation::STATUS_NO_SHOW])
        ->assertOk();

    $package->refresh();
    $usage = CustomerPackageUsage::query()->firstOrFail();

    expect($package->remaining_quantity)->toBe(2)
        ->and($package->consumed_quantity)->toBe(0)
        ->and($package->status)->toBe(CustomerPackage::STATUS_ACTIVE)
        ->and($usage->reversed_at)->not->toBeNull()
        ->and(data_get($reservation->fresh()->metadata, 'customer_package.status'))->toBe('restored');

    Carbon::setTestNow();
});

it('expires forfaits and prepares balance and marketing reminders from automation', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-05-11 08:00:00', 'UTC'));

    $owner = customerPackagesPhaseFourOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $service = customerPackagesPhaseFourProduct($owner);
    $offer = customerPackagesPhaseFourOffer($owner, $service);
    $packageService = app(CustomerPackageService::class);

    $expired = $packageService->assign($owner, $customer, $offer, [
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-05-10',
        'initial_quantity' => 5,
    ]);
    $lowBalance = $packageService->assign($owner, $customer, $offer, [
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-06-30',
        'initial_quantity' => 5,
    ]);
    $lowBalance->forceFill([
        'consumed_quantity' => 4,
        'remaining_quantity' => 1,
    ])->save();
    $expiringSoon = $packageService->assign($owner, $customer, $offer, [
        'starts_at' => '2026-04-01',
        'expires_at' => '2026-05-15',
        'initial_quantity' => 5,
    ]);

    $this->artisan('offer-packages:automation --date=2026-05-11')
        ->expectsOutput('Offer package automation: expired 1, low balance alerts 1, marketing reminders 1, renewal reminders 0, renewal invoices 0, paid renewals 0, suspended renewals 0.')
        ->assertExitCode(0);

    expect($expired->fresh()->status)->toBe(CustomerPackage::STATUS_EXPIRED)
        ->and(data_get($lowBalance->fresh()->metadata, 'automation.notifications.low_balance_sent_at.sent_at'))->not->toBeNull()
        ->and(data_get($expiringSoon->fresh()->metadata, 'automation.notifications.expiring_soon_sent_at.sent_at'))->not->toBeNull()
        ->and(ActivityLog::query()->whereIn('action', [
            'customer_package_expired',
            'customer_package_low_balance',
            'customer_package_expiring_soon',
        ])->count())->toBe(3);

    Notification::assertSentTo($owner, CampaignInAppNotification::class);

    Carbon::setTestNow();
});
