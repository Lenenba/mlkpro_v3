<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\TenantBrandingResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

function tenantBrandingContextRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => ucfirst($name).' role'],
    )->id;
}

function tenantBrandingContextOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => tenantBrandingContextRoleId('owner'),
        'company_type' => 'services',
        'company_features' => [],
        'onboarding_completed_at' => now(),
        'email_verified_at' => now(),
        'two_factor_exempt' => true,
    ], $attributes));
}

/**
 * @return array{0: User, 1: Customer}
 */
function tenantBrandingContextPortalClient(User $owner): array
{
    $client = User::factory()->create([
        'role_id' => tenantBrandingContextRoleId('client'),
        'email_verified_at' => now(),
        'two_factor_exempt' => true,
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'email' => $client->email,
    ]);

    return [$client, $customer];
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('public store and showcase payloads expose normalized tenant branding without cross-tenant leakage', function () {
    $firstStore = tenantBrandingContextOwner([
        'company_name' => 'Boutique Boréale',
        'company_type' => 'products',
        'company_slug' => 'boutique-boreale-branding',
        'company_logo' => 'https://assets.example.test/boutique-boreale.png',
    ]);
    $secondStore = tenantBrandingContextOwner([
        'company_name' => 'Marché du Sud',
        'company_type' => 'products',
        'company_slug' => 'marche-du-sud-branding',
        'company_logo' => 'https://assets.example.test/marche-du-sud.png',
    ]);
    $showcase = tenantBrandingContextOwner([
        'name' => 'Atelier sans logo',
        'company_name' => null,
        'company_type' => 'services',
        'company_slug' => 'atelier-sans-logo-branding',
        'company_logo' => 'customers/customer.png',
        'company_features' => ['requests' => false],
    ]);

    $this->get(route('public.store.show', $firstStore->company_slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Store')
            ->where('company.name', 'Boutique Boréale')
            ->where('company.logo_url', 'https://assets.example.test/boutique-boreale.png')
            ->where('company.custom_logo_url', 'https://assets.example.test/boutique-boreale.png')
            ->where('company.has_custom_logo', true));

    $this->get(route('public.store.show', $secondStore->company_slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Store')
            ->where('company.name', 'Marché du Sud')
            ->where('company.logo_url', 'https://assets.example.test/marche-du-sud.png')
            ->where('company.custom_logo_url', 'https://assets.example.test/marche-du-sud.png')
            ->where('company.has_custom_logo', true));

    $this->get(route('public.showcase.show', $showcase->company_slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Showcase')
            ->where('company.name', 'Atelier sans logo')
            ->where('company.logo_url', null)
            ->where('company.custom_logo_url', null)
            ->where('company.has_custom_logo', false));
});

test('portal branding stays isolated to the customer workspace across orders invoices and packages', function () {
    $firstOwner = tenantBrandingContextOwner([
        'company_name' => 'Portail Nord',
        'company_type' => 'products',
        'company_logo' => 'https://assets.example.test/portail-nord.png',
        'currency_code' => 'CAD',
    ]);
    $secondOwner = tenantBrandingContextOwner([
        'company_name' => 'Portail Sud',
        'company_type' => 'products',
        'company_logo' => 'https://assets.example.test/portail-sud.png',
        'currency_code' => 'USD',
    ]);
    [$firstClient, $firstCustomer] = tenantBrandingContextPortalClient($firstOwner);
    [$secondClient] = tenantBrandingContextPortalClient($secondOwner);

    $this->actingAs($firstClient)
        ->get(route('portal.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Products/Shop')
            ->where('company.id', $firstOwner->id)
            ->where('company.name', 'Portail Nord')
            ->where('company.logo_url', 'https://assets.example.test/portail-nord.png')
            ->where('company.custom_logo_url', 'https://assets.example.test/portail-nord.png')
            ->where('company.has_custom_logo', true)
            ->where('company.currency_code', 'CAD'));

    $this->actingAs($secondClient)
        ->get(route('portal.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Products/Shop')
            ->where('company.id', $secondOwner->id)
            ->where('company.name', 'Portail Sud')
            ->where('company.logo_url', 'https://assets.example.test/portail-sud.png')
            ->where('company.custom_logo_url', 'https://assets.example.test/portail-sud.png')
            ->where('company.has_custom_logo', true)
            ->where('company.currency_code', 'USD'));

    $invoice = Invoice::query()->create([
        'user_id' => $firstOwner->id,
        'customer_id' => $firstCustomer->id,
        'status' => 'sent',
        'subtotal' => 125,
        'tax_total' => 0,
        'total' => 125,
    ]);

    $this->actingAs($firstClient)
        ->get(route('portal.invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/InvoiceShow')
            ->where('company.name', 'Portail Nord')
            ->where('company.logo_url', 'https://assets.example.test/portail-nord.png')
            ->where('company.custom_logo_url', 'https://assets.example.test/portail-nord.png')
            ->where('company.has_custom_logo', true));

    $this->actingAs($firstClient)
        ->get(route('portal.packages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Packages/Index')
            ->where('company.name', 'Portail Nord')
            ->where('company.logo_url', 'https://assets.example.test/portail-nord.png')
            ->where('company.custom_logo_url', 'https://assets.example.test/portail-nord.png')
            ->where('company.has_custom_logo', true));

    $sale = Sale::query()->create([
        'user_id' => $firstOwner->id,
        'customer_id' => $firstCustomer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 32,
        'tax_total' => 0,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'total' => 32,
        'fulfillment_method' => 'pickup',
        'fulfillment_status' => Sale::FULFILLMENT_PENDING,
    ]);

    Sanctum::actingAs($firstClient);

    $this->getJson('/api/v1/portal/orders/'.$sale->id)
        ->assertOk()
        ->assertJsonPath('company.id', $firstOwner->id)
        ->assertJsonPath('company.name', 'Portail Nord')
        ->assertJsonPath('company.logo_url', 'https://assets.example.test/portail-nord.png')
        ->assertJsonPath('company.custom_logo_url', 'https://assets.example.test/portail-nord.png')
        ->assertJsonPath('company.has_custom_logo', true)
        ->assertJsonPath('company.currency_code', 'CAD');

    Sanctum::actingAs($secondClient);

    $this->getJson('/api/v1/portal/orders/'.$sale->id)
        ->assertNotFound();

    $crossTenantInvoice = Invoice::query()->create([
        'user_id' => $secondOwner->id,
        'customer_id' => $firstCustomer->id,
        'status' => 'sent',
        'subtotal' => 75,
        'tax_total' => 0,
        'total' => 75,
    ]);

    $this->actingAs($firstClient)
        ->getJson(route('portal.invoices.show', $crossTenantInvoice))
        ->assertForbidden();

    $this->actingAs($secondClient)
        ->getJson(route('portal.invoices.show', $invoice))
        ->assertForbidden();
});

test('portal order pdf receives the authorized tenant and renders its normalized brand', function () {
    $owner = tenantBrandingContextOwner([
        'company_name' => 'Commande Boréale',
        'company_type' => 'products',
        'company_logo' => 'https://assets.example.test/commande-boreale-wide.png',
    ]);
    $otherOwner = tenantBrandingContextOwner([
        'company_name' => 'Commande étrangère',
        'company_type' => 'products',
        'company_logo' => 'https://assets.example.test/commande-etrangere.png',
    ]);
    [$client, $customer] = tenantBrandingContextPortalClient($owner);
    [$otherClient] = tenantBrandingContextPortalClient($otherOwner);
    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 42,
        'tax_total' => 0,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'total' => 42,
        'fulfillment_method' => 'pickup',
        'fulfillment_status' => Sale::FULFILLMENT_PENDING,
    ]);

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data) use ($owner): bool {
            $branding = app(TenantBrandingResolver::class)->forAccountOwner($data['company'] ?? null);
            $html = view($view, $data)->render();

            expect($view)->toBe('pdf.order')
                ->and($data['company']->is($owner))->toBeTrue()
                ->and($branding['name'])->toBe('Commande Boréale')
                ->and($branding['custom_logo_url'])->toBe('https://assets.example.test/commande-boreale-wide.png')
                ->and($html)->toContain('Commande Boréale')
                ->and($html)->toContain('https://assets.example.test/commande-boreale-wide.png')
                ->and($html)->not->toContain('commande-etrangere.png');

            return true;
        })
        ->andReturnSelf();
    Pdf::shouldReceive('setOption')
        ->once()
        ->with('isRemoteEnabled', true)
        ->andReturnSelf();
    Pdf::shouldReceive('download')
        ->once()
        ->with('order-'.$sale->number.'.pdf')
        ->andReturn(response('pdf-content', 200, ['Content-Type' => 'application/pdf']));

    $this->actingAs($client)
        ->get(route('portal.orders.pdf', $sale))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($otherClient)
        ->getJson(route('portal.orders.pdf', $sale))
        ->assertNotFound();
});

test('two factor verification and confirmation use trusted tenant context while reset stays platform branded', function () {
    $owner = tenantBrandingContextOwner([
        'company_name' => 'Auth Contexte',
        'company_logo' => 'https://assets.example.test/auth-contexte.png',
        'two_factor_exempt' => false,
        'two_factor_method' => 'app',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $this->actingAs($owner)
        ->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/TwoFactorChallenge')
            ->where('auth.account.company.name', 'Auth Contexte')
            ->where('auth.account.company.logo_url', 'https://assets.example.test/auth-contexte.png')
            ->where('auth.account.company.has_custom_logo', true));

    $employee = User::factory()->unverified()->create([
        'role_id' => tenantBrandingContextRoleId('employee'),
        'company_name' => 'Marque employé incorrecte',
        'company_logo' => 'https://assets.example.test/employee-incorrect.png',
    ]);
    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $this->actingAs($employee)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/VerifyEmail')
            ->where('auth.account.owner_id', $owner->id)
            ->where('auth.account.company.name', 'Auth Contexte')
            ->where('auth.account.company.logo_url', 'https://assets.example.test/auth-contexte.png'));

    [$client] = tenantBrandingContextPortalClient($owner);

    $this->actingAs($client)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ConfirmPassword')
            ->where('auth.account.owner_id', $owner->id)
            ->where('auth.account.company.name', 'Auth Contexte')
            ->where('auth.account.company.logo_url', 'https://assets.example.test/auth-contexte.png'));

    auth()->logout();
    $token = Password::broker()->createToken($owner);

    $this->get(route('password.reset', [
        'token' => $token,
        'email' => $owner->email,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ResetPassword')
            ->where('auth.user', null)
            ->where('auth.account', null)
            ->missing('company'));
});

test('impersonation keeps platform context outside the session and shares only the selected tenant brand inside it', function () {
    $superadmin = User::factory()->create([
        'role_id' => tenantBrandingContextRoleId('superadmin'),
        'company_name' => 'Administration plateforme',
        'company_logo' => 'https://assets.example.test/platform-only.png',
        'email_verified_at' => now(),
        'onboarding_completed_at' => now(),
    ]);
    $selectedTenant = tenantBrandingContextOwner([
        'company_name' => 'Tenant sélectionné',
        'company_logo' => 'https://assets.example.test/tenant-selected.png',
    ]);
    tenantBrandingContextOwner([
        'company_name' => 'Tenant voisin',
        'company_logo' => 'https://assets.example.test/tenant-neighbor.png',
    ]);

    $this->actingAs($superadmin)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('auth.account.is_superadmin', true)
            ->where('auth.account.is_platform_admin', false)
            ->where('auth.account.company.name', 'Administration plateforme')
            ->where('auth.impersonator', null));

    $this->actingAs($superadmin)
        ->post(route('superadmin.tenants.impersonate', $selectedTenant))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('impersonator_id', $superadmin->id);

    $this->assertAuthenticatedAs($selectedTenant);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('auth.user.id', $selectedTenant->id)
            ->where('auth.account.is_superadmin', false)
            ->where('auth.account.owner_id', $selectedTenant->id)
            ->where('auth.account.company.name', 'Tenant sélectionné')
            ->where('auth.account.company.logo_url', 'https://assets.example.test/tenant-selected.png')
            ->where('auth.account.company.custom_logo_url', 'https://assets.example.test/tenant-selected.png')
            ->where('auth.account.company.has_custom_logo', true)
            ->where('auth.impersonator.id', $superadmin->id)
            ->where('auth.impersonator.name', $superadmin->name)
            ->where('auth.impersonator.email', $superadmin->email));

    $this->post(route('superadmin.impersonate.stop'))
        ->assertRedirect(route('superadmin.dashboard'))
        ->assertSessionMissing('impersonator_id');

    $this->assertAuthenticatedAs($superadmin);
});
