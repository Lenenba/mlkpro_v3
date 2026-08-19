<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\PublicLeadFormUrlService;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

test('priority public tenant pages expose a nullable custom logo without the legacy placeholder', function () {
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role'],
    );
    $owner = User::factory()->create([
        'role_id' => $ownerRole->id,
        'company_name' => 'Branding Pending Co.',
        'company_type' => 'services',
        'company_logo' => 'customers/customer.png',
        'company_branding_settings' => ['primary_color' => '#123ABC'],
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'auto_validate_jobs' => false,
        'auto_validate_tasks' => false,
    ]);
    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Public quote',
        'status' => 'sent',
        'subtotal' => 100,
        'total' => 100,
        'initial_deposit' => 0,
    ]);
    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Public work',
        'instructions' => 'Public work instructions',
        'status' => Work::STATUS_IN_PROGRESS,
    ]);
    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'subtotal' => 100,
        'total' => 100,
    ]);

    $routes = [
        ['public.invoices.show', ['invoice' => $invoice->id], 'Public/InvoicePay'],
        ['public.quotes.show', ['quote' => $quote->id], 'Public/QuoteAction'],
        ['public.works.show', ['work' => $work->id], 'Public/WorkAction'],
        ['public.works.proofs', ['work' => $work->id], 'Public/WorkProofs'],
        ['public.requests.form', ['user' => $owner->id], 'Public/RequestForm'],
    ];

    foreach ($routes as [$routeName, $parameters, $component]) {
        $this->get(URL::temporarySignedRoute($routeName, now()->addMinutes(30), $parameters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->where('company.name', 'Branding Pending Co.')
                ->where('company.logo_url', null)
                ->where('company.custom_logo_url', null)
                ->where('company.has_custom_logo', false)
                ->where('company.primary_color', '#123ABC')
                ->where('company.primary_hover_color', '#1033A5')
                ->where('company.primary_focus_color', '#0E2D93')
                ->where('company.primary_foreground_color', '#FFFFFF')
                ->where('company.has_custom_primary_color', true));
    }
});

test('public request links always resolve employee context to the account owner', function () {
    $owner = User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role'],
        )->id,
        'company_name' => 'Owner Request Brand',
        'company_logo' => 'https://assets.example.test/owner-request.png',
        'company_branding_settings' => ['primary_color' => '#123ABC'],
        'company_type' => 'services',
        'company_features' => ['requests' => true],
        'onboarding_completed_at' => now(),
    ]);
    $employee = User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(
            ['name' => 'employee'],
            ['description' => 'Employee role'],
        )->id,
        'company_name' => 'Incorrect Employee Brand',
        'company_logo' => 'https://assets.example.test/employee-request.png',
        'company_branding_settings' => ['primary_color' => '#FDE047'],
    ]);
    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'employee',
        'permissions' => [],
        'is_active' => true,
    ]);

    $employeeUrl = URL::signedRoute('public.requests.form', ['user' => $employee->id]);
    $ownerUrl = URL::signedRoute('public.requests.form', ['user' => $owner->id]);

    $this->get($employeeUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/RequestForm')
            ->where('company.id', $owner->id)
            ->where('company.name', 'Owner Request Brand')
            ->where('company.logo_url', 'https://assets.example.test/owner-request.png')
            ->where('company.has_custom_logo', true)
            ->where('company.primary_color', '#123ABC')
            ->where('company.has_custom_primary_color', true));

    expect(app(PublicLeadFormUrlService::class)->resolve($employee->id))->toBe($ownerUrl);
});

test('public request link resolution never falls back to an unrelated tenant', function () {
    User::factory()->create([
        'role_id' => Role::query()->firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role'],
        )->id,
        'company_features' => ['requests' => true],
    ]);

    $service = app(PublicLeadFormUrlService::class);

    expect($service->resolve())->toBeNull()
        ->and($service->resolve(999999))->toBeNull();
});
