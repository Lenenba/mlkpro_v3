<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InviteUserNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function customerPortalLifecycleRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => ucfirst($name).' access']
    )->id;
}

function customerPortalLifecycleOwner(array $attributes = []): User
{
    return User::factory()
        ->withRole(customerPortalLifecycleRoleId('owner'))
        ->create(array_merge([
            'name' => 'Portal Lifecycle Owner',
            'email' => 'portal-lifecycle-owner@example.com',
        ], $attributes));
}

function customerPortalLifecycleUser(string $role, array $attributes = []): User
{
    return User::factory()
        ->withRole(customerPortalLifecycleRoleId($role))
        ->create($attributes);
}

function customerPortalLifecycleCustomer(User $owner, array $attributes = []): Customer
{
    return Customer::query()->create(array_merge([
        'user_id' => $owner->id,
        'portal_user_id' => null,
        'portal_access' => false,
        'client_type' => 'individual',
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'email' => 'amina.portal@example.com',
        'phone' => '+15145550110',
        'salutation' => 'Mrs',
    ], $attributes));
}

/**
 * @return array<string, mixed>
 */
function customerPortalLifecyclePayload(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'client_type' => $customer->client_type,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'email' => $customer->email,
        'phone' => $customer->phone,
        'portal_access' => $customer->portal_access,
        'billing_mode' => 'end_of_job',
        'billing_grouping' => 'single',
    ], $overrides);
}

it('creates and invites a linked client user when portal access is requested', function () {
    $owner = customerPortalLifecycleOwner();
    Notification::fake();

    $response = $this->actingAs($owner)->postJson(route('customer.store'), [
        'client_type' => 'individual',
        'first_name' => 'Nadia',
        'last_name' => 'Benali',
        'email' => 'nadia.portal@example.com',
        'phone' => '+15145550111',
        'portal_access' => true,
    ]);

    $response->assertCreated();
    $customer = Customer::query()->where('email', 'nadia.portal@example.com')->firstOrFail();
    $portalUser = User::query()->findOrFail($customer->portal_user_id);

    expect($customer->portal_access)->toBeTrue()
        ->and($portalUser->email)->toBe('nadia.portal@example.com')
        ->and($portalUser->isClient())->toBeTrue()
        ->and($portalUser->must_change_password)->toBeTrue();
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertSentToTimes($portalUser, InviteUserNotification::class, 1);
});

it('quick creates a customer by linking and inviting an existing client user once', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'quick.existing.client@example.com',
        'must_change_password' => false,
    ]);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->postJson(route('customer.quick.store'), [
        'client_type' => 'individual',
        'first_name' => 'Fatou',
        'last_name' => 'Baptiste',
        'email' => $portalUser->email,
        'phone' => '+15145550112',
        'portal_access' => true,
    ]);

    $response->assertCreated()->assertJsonPath('invite_sent', true);
    $customer = Customer::query()->where('email', $portalUser->email)->firstOrFail();

    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'user_id' => $owner->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertSentToTimes($portalUser, InviteUserNotification::class, 1);
});

it('provisions and invites a client user when portal access is enabled later', function () {
    $owner = customerPortalLifecycleOwner();
    $customer = customerPortalLifecycleCustomer($owner);
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['portal_access' => true])
    );

    $response->assertOk();
    $customer->refresh();
    $portalUser = User::query()->findOrFail($customer->portal_user_id);

    expect($customer->portal_access)->toBeTrue()
        ->and($portalUser->email)->toBe($customer->email)
        ->and($portalUser->isClient())->toBeTrue()
        ->and($portalUser->must_change_password)->toBeTrue();
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertSentToTimes($portalUser, InviteUserNotification::class, 1);
});

it('keeps an enabled portal account idempotent without another user or invitation', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'enabled.portal@example.com',
        'must_change_password' => true,
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => $portalUser->email,
    ]);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['portal_access' => true])
    );

    $response->assertOk();
    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertNotSentTo($portalUser, InviteUserNotification::class);
});

it('preserves enabled portal access when an update omits the portal access field', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'preserved.portal@example.com',
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => $portalUser->email,
    ]);
    $payload = customerPortalLifecyclePayload($customer);
    unset($payload['portal_access']);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        $payload
    );

    $response->assertOk();
    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertNotSentTo($portalUser, InviteUserNotification::class);
});

it('links and invites an existing unlinked client user with the customer email', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'existing.client@example.com',
        'must_change_password' => false,
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'email' => $portalUser->email,
    ]);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['portal_access' => true])
    );

    $response->assertOk();
    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertSentToTimes($portalUser, InviteUserNotification::class, 1);
});

it('returns 422 when the customer email belongs to a non-client user', function () {
    $owner = customerPortalLifecycleOwner();
    $nonClient = customerPortalLifecycleUser('employee', [
        'email' => 'employee.collision@example.com',
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'email' => $nonClient->email,
    ]);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['portal_access' => true])
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => null,
        'portal_access' => false,
    ]);
    Notification::assertNothingSent();
});

it('returns 422 when the matching client user is linked to another customer', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'linked.client@example.com',
    ]);
    $linkedCustomer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => 'other.customer@example.com',
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'email' => $portalUser->email,
        'first_name' => 'Second',
        'last_name' => 'Customer',
    ]);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['portal_access' => true])
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => null,
        'portal_access' => false,
    ]);
    $this->assertDatabaseHas('customers', [
        'id' => $linkedCustomer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertNothingSent();
});

it('synchronizes the linked portal user email without sending another invitation', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'old.portal@example.com',
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => $portalUser->email,
    ]);
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['email' => 'new.portal@example.com'])
    );

    $response->assertOk();
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => 'new.portal@example.com',
    ]);
    $this->assertDatabaseHas('users', [
        'id' => $portalUser->id,
        'email' => 'new.portal@example.com',
    ]);
    Notification::assertNotSentTo($portalUser, InviteUserNotification::class);
});

it('provisions and invites every eligible customer when portal access is bulk enabled', function () {
    $owner = customerPortalLifecycleOwner();
    $firstCustomer = customerPortalLifecycleCustomer($owner, [
        'email' => 'bulk.first@example.com',
    ]);
    $secondCustomer = customerPortalLifecycleCustomer($owner, [
        'email' => 'bulk.second@example.com',
        'first_name' => 'Mariam',
        'last_name' => 'Traore',
    ]);
    $userCount = User::query()->count();
    Notification::fake();

    $response = $this->actingAs($owner)->postJson(route('customer.bulk'), [
        'action' => 'portal_enable',
        'ids' => [$firstCustomer->id, $secondCustomer->id],
    ]);

    $response->assertOk();
    $this->assertDatabaseCount('users', $userCount + 2);

    foreach ([$firstCustomer, $secondCustomer] as $customer) {
        $customer->refresh();
        $portalUser = User::query()->findOrFail($customer->portal_user_id);

        expect($portalUser->email)->toBe($customer->email)
            ->and($portalUser->isClient())->toBeTrue()
            ->and($portalUser->must_change_password)->toBeTrue();
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'portal_user_id' => $portalUser->id,
            'portal_access' => true,
        ]);
        Notification::assertSentToTimes($portalUser, InviteUserNotification::class, 1);
    }
});

it('resends a fresh portal invitation token without replacing the linked user', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'resend.portal@example.com',
        'must_change_password' => true,
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => $portalUser->email,
    ]);
    $oldToken = Password::broker()->createToken($portalUser);
    $userCount = User::query()->count();
    $freshToken = null;
    Notification::fake();

    $response = $this->actingAs($owner)->postJson(
        route('customer.portal-invitation.resend', $customer)
    );

    $response->assertOk();
    $this->assertDatabaseCount('users', $userCount);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
    ]);
    Notification::assertSentTo(
        $portalUser,
        InviteUserNotification::class,
        function (InviteUserNotification $notification) use ($portalUser, &$freshToken): bool {
            $actionUrl = (string) $notification->toMail($portalUser)->viewData['actionUrl'];
            $freshToken = rawurldecode(basename((string) parse_url($actionUrl, PHP_URL_PATH)));

            return $freshToken !== '';
        }
    );
    Notification::assertSentToTimes($portalUser, InviteUserNotification::class, 1);

    expect($freshToken)->not->toBeNull()
        ->and($freshToken)->not->toBe($oldToken)
        ->and(Password::broker()->tokenExists($portalUser, $oldToken))->toBeFalse()
        ->and(Password::broker()->tokenExists($portalUser, (string) $freshToken))->toBeTrue();
});

it('returns 422 without a token or notification when resending for disabled portal access', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'resend.disabled.portal@example.com',
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => false,
        'email' => $portalUser->email,
    ]);
    Notification::fake();

    $response = $this->actingAs($owner)->postJson(
        route('customer.portal-invitation.resend', $customer)
    );

    $response->assertUnprocessable()->assertJsonValidationErrors('portal_access');
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => false,
    ]);
    $this->assertDatabaseMissing('password_reset_tokens', [
        'email' => $portalUser->email,
    ]);
    Notification::assertNothingSent();
});

it('disables portal access without deleting or unlinking the client user', function () {
    $owner = customerPortalLifecycleOwner();
    $portalUser = customerPortalLifecycleUser('client', [
        'email' => 'disabled.portal@example.com',
    ]);
    $customer = customerPortalLifecycleCustomer($owner, [
        'portal_user_id' => $portalUser->id,
        'portal_access' => true,
        'email' => $portalUser->email,
    ]);
    $portalUser->createToken('portal-before-disable');
    Password::broker()->createToken($portalUser);
    Notification::fake();

    $response = $this->actingAs($owner)->putJson(
        route('customer.update', $customer),
        customerPortalLifecyclePayload($customer, ['portal_access' => false])
    );

    $response->assertOk();
    $this->assertModelExists($portalUser);
    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'portal_access' => false,
    ]);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $portalUser->id,
    ]);
    $this->assertDatabaseMissing('password_reset_tokens', [
        'email' => $portalUser->email,
    ]);
    expect($portalUser->fresh()?->remember_token)->not->toBeNull();
    Notification::assertNotSentTo($portalUser, InviteUserNotification::class);
});
