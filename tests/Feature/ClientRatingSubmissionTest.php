<?php

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

/** @return array{owner: User, client: User, customer: Customer} */
function clientRatingContext(): array
{
    $owner = User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(['name' => 'owner'], ['description' => 'Owner'])->id,
        'company_type' => 'services',
        'company_features' => ['reservations' => true, 'jobs' => true],
        'onboarding_completed_at' => now(),
    ]);
    $client = User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(['name' => 'client'], ['description' => 'Client'])->id,
        'onboarding_completed_at' => now(),
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id, 'portal_user_id' => $client->id, 'portal_access' => true,
        'email' => $client->email,
    ]);

    return compact('owner', 'client', 'customer');
}

it('accepts a reservation review immediately after completion before the planned end time', function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-09-05 14:30:00', 'UTC'));
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = clientRatingContext();
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $customer->id, 'client_user_id' => $client->id,
        'status' => Reservation::STATUS_COMPLETED,
        'starts_at' => '2026-09-05 14:00:00', 'ends_at' => '2026-09-05 15:00:00',
        'status_changed_at' => now(), 'status_change_source' => Reservation::STATUS_CHANGE_SOURCE_QUEUE_STAFF,
    ]);

    $this->actingAs($client)->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.review', $reservation), ['rating' => 4, 'feedback' => 'Très bon service.'])
        ->assertCreated()->assertJsonPath('review.rating', 4)->assertJsonPath('review.feedback', 'Très bon service.');

    $this->assertDatabaseHas('reservation_reviews', [
        'reservation_id' => $reservation->id, 'account_id' => $owner->id,
        'client_user_id' => $client->id, 'rating' => 4, 'feedback' => 'Très bon service.',
    ]);
    expect($reservation->refresh()->ends_at->format('Y-m-d H:i:s'))->toBe('2026-09-05 15:00:00');
    Notification::assertSentTo($owner, ActionEmailNotification::class);
});

it('returns 422 for a reservation review until the service is completed even after its planned end', function (string $status) {
    Notification::fake();
    $this->freezeTime();
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = clientRatingContext();
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $customer->id, 'client_user_id' => $client->id,
        'status' => $status, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
    ]);

    $this->actingAs($client)->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.review', $reservation), ['rating' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');

    $this->assertDatabaseCount('reservation_reviews', 0);
    Notification::assertNothingSent();
})->with([
    Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED, Reservation::STATUS_RESCHEDULED,
    Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW, Reservation::STATUS_EXPIRED,
]);

it('forbids reviewing another customer reservation without saving a note', function () {
    Notification::fake();
    ['owner' => $owner, 'client' => $client] = clientRatingContext();
    $otherCustomer = Customer::factory()->create(['user_id' => $owner->id]);
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $otherCustomer->id, 'client_user_id' => null,
        'status' => Reservation::STATUS_COMPLETED,
    ]);

    $this->actingAs($client)->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.review', $reservation), ['rating' => 5])->assertForbidden();

    $this->assertDatabaseCount('reservation_reviews', 0);
    Notification::assertNothingSent();
});

it('returns 422 for an invalid note on a completed reservation', function () {
    Notification::fake();
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = clientRatingContext();
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $customer->id, 'client_user_id' => $client->id,
        'status' => Reservation::STATUS_COMPLETED,
    ]);

    $this->actingAs($client)->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.review', $reservation), ['rating' => 6])
        ->assertUnprocessable()->assertJsonValidationErrors('rating');

    $this->assertDatabaseCount('reservation_reviews', 0);
    Notification::assertNothingSent();
});

it('saves a completed service rating and returns its JSON confirmation', function () {
    Notification::fake();
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = clientRatingContext();
    $work = Work::factory()->create([
        'user_id' => $owner->id, 'customer_id' => $customer->id,
        'status' => Work::STATUS_COMPLETED, 'completed_at' => now(),
    ]);

    $this->actingAs($client)->withSession(['two_factor_passed' => true])
        ->postJson(route('portal.works.ratings.store', $work), ['rating' => 4, 'feedback' => 'Service soigné.'])
        ->assertCreated()->assertJsonPath('rating.rating', 4)->assertJsonPath('rating.feedback', 'Service soigné.');

    $this->assertDatabaseHas('work_ratings', ['work_id' => $work->id, 'user_id' => $client->id, 'rating' => 4, 'feedback' => 'Service soigné.']);
    Notification::assertSentTo($owner, ActionEmailNotification::class);
});

it('redirects the client dashboard after an Inertia service rating instead of returning raw JSON', function () {
    Notification::fake();
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = clientRatingContext();
    $work = Work::factory()->create([
        'user_id' => $owner->id, 'customer_id' => $customer->id,
        'status' => Work::STATUS_COMPLETED, 'completed_at' => now(),
    ]);

    $this->actingAs($client)->withSession(['two_factor_passed' => true])->from(route('dashboard'))
        ->withHeader('X-Inertia', 'true')
        ->post(route('portal.works.ratings.store', $work), ['rating' => '5', 'feedback' => ''])
        ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('work_ratings', ['work_id' => $work->id, 'user_id' => $client->id, 'rating' => 5, 'feedback' => null]);
    Notification::assertSentTo($owner, ActionEmailNotification::class);
});
