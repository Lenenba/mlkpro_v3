<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Demo\DemoAccountService;
use App\Services\Demo\DemoSeedService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Tighten\Ziggy\BladeRouteGenerator;
use Tighten\Ziggy\Ziggy;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    BladeRouteGenerator::$generated = false;
});

function p1002ZiggyRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => "P1-002 {$name} role"]
    )->id;
}

function p1002ZiggyOwner(): User
{
    return User::query()->create([
        'name' => 'P1-002 Owner',
        'email' => 'p1002-owner-'.Str::lower(Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => p1002ZiggyRoleId('owner'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);
}

function p1002ZiggyClient(User $owner): User
{
    $client = User::query()->create([
        'name' => 'P1-002 Client',
        'email' => 'p1002-client-'.Str::lower(Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => p1002ZiggyRoleId('client'),
        'onboarding_completed_at' => now(),
    ]);

    Customer::query()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'first_name' => 'P1-002',
        'last_name' => 'Client',
        'company_name' => 'P1-002 Portal',
        'email' => 'p1002-customer-'.Str::lower(Str::random(10)).'@example.com',
        'phone' => '+15145550100',
    ]);

    return $client;
}

function p1002ZiggySuperadmin(): User
{
    return User::query()->create([
        'name' => 'P1-002 Superadmin',
        'email' => 'p1002-superadmin-'.Str::lower(Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => p1002ZiggyRoleId('superadmin'),
        'onboarding_completed_at' => now(),
    ]);
}

function p1002ZiggyRouteNames(string|array|null $group): array
{
    return array_keys((new Ziggy($group))->toArray()['routes']);
}

it('keeps every configured route group smaller than the complete Ziggy map', function () {
    $complete = (new Ziggy)->toJson();
    $public = (new Ziggy('public'))->toJson();
    $portal = (new Ziggy(['public', 'portal']))->toJson();
    $admin = (new Ziggy('admin'))->toJson();

    expect(strlen($public))->toBeLessThan(strlen($complete))
        ->and(strlen($portal))->toBeLessThan(strlen($complete))
        ->and(strlen($admin))->toBeLessThan(strlen($complete));
});

it('keeps critical routes inside their surface and excludes another surface', function () {
    $public = p1002ZiggyRouteNames('public');
    $portal = p1002ZiggyRouteNames(['public', 'portal']);
    $admin = p1002ZiggyRouteNames('admin');

    expect($public)->toContain('public.store.show', 'login', 'demo.login')
        ->not->toContain('portal.orders.index', 'superadmin.tenants.index')
        ->and($portal)->toContain('public.store.show', 'portal.orders.index', 'client.reservations.index')
        ->not->toContain('superadmin.tenants.index', 'customer.index')
        ->and($admin)->toContain('dashboard', 'customer.index', 'superadmin.tenants.index')
        ->not->toContain('portal.orders.index', 'public.store.show');
});

it('renders the selected public, portal and admin maps in their HTML documents', function () {
    BladeRouteGenerator::$generated = false;
    $this->get(route('welcome'))
        ->assertOk()
        ->assertSee('public.store.show', false)
        ->assertDontSee('superadmin.tenants.index', false);

    config()->set('demo.enabled', true);
    BladeRouteGenerator::$generated = false;
    $this->get(route('demo.index'))
        ->assertOk()
        ->assertSee('demo.login', false)
        ->assertDontSee('superadmin.tenants.index', false);

    $owner = p1002ZiggyOwner();
    $client = p1002ZiggyClient($owner);

    BladeRouteGenerator::$generated = false;
    $this->actingAs($client)
        ->withSession(['two_factor_passed' => true])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('portal.orders.index', false)
        ->assertDontSee('superadmin.tenants.index', false);

    $superadmin = p1002ZiggySuperadmin();

    BladeRouteGenerator::$generated = false;
    $this->actingAs($superadmin)
        ->withSession(['two_factor_passed' => true])
        ->get(route('superadmin.dashboard'))
        ->assertOk()
        ->assertSee('superadmin.tenants.index', false)
        ->assertDontSee('portal.orders.index', false);
});

it('reloads the document when an Inertia logout crosses from admin to public', function () {
    $owner = p1002ZiggyOwner();

    $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('logout'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('welcome'));
});

it('reloads the document when an Inertia demo login enters the workspace', function () {
    config()->set('demo.enabled', true);
    $demoAccount = p1002ZiggyOwner();

    $accounts = \Mockery::mock(DemoAccountService::class);
    $accounts->shouldReceive('resolveDemoAccount')
        ->once()
        ->with('service')
        ->andReturn($demoAccount);
    app()->instance(DemoAccountService::class, $accounts);

    $seeds = \Mockery::mock(DemoSeedService::class);
    $seeds->shouldReceive('seed')
        ->once()
        ->with($demoAccount, 'service');
    app()->instance(DemoSeedService::class, $seeds);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('demo.login', 'service'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('dashboard'));
});

it('reloads the document when an Inertia account deletion returns to public', function () {
    $owner = p1002ZiggyOwner();

    $accountDeletion = \Mockery::mock(AccountDeletionService::class);
    $accountDeletion->shouldReceive('deleteAccount')
        ->once()
        ->with($owner);
    app()->instance(AccountDeletionService::class, $accountDeletion);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->withHeaders(['X-Inertia' => 'true'])
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('welcome'));
});
