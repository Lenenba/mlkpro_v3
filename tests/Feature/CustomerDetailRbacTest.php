<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\CompanyRole;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Database\Seeders\RbacSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    $this->seed(RbacSeeder::class);
});

/**
 * @param  array<string, bool>  $features
 */
function customerDetailRbacOwner(array $features = []): User
{
    $ownerRole = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role'],
    );

    return User::factory()->create([
        'role_id' => $ownerRole->id,
        'company_type' => 'services',
        'company_sector' => 'professional_services',
        'company_timezone' => 'America/Toronto',
        'currency_code' => 'CAD',
        'selected_plan_key' => null,
        'onboarding_completed_at' => now(),
        'company_features' => array_replace([
            'requests' => true,
            'quotes' => true,
            'jobs' => true,
            'tasks' => true,
            'invoices' => false,
            'reservations' => false,
            'sales' => true,
            'sales_crm' => true,
            'campaigns' => false,
            'loyalty' => false,
        ], $features),
    ]);
}

/**
 * @param  list<string>  $permissionSlugs
 */
function customerDetailRbacRole(User $owner, array $permissionSlugs): CompanyRole
{
    $roleNumber = CompanyRole::query()->where('company_id', $owner->id)->count() + 1;
    $role = CompanyRole::query()->create([
        'company_id' => $owner->id,
        'name' => 'Customer detail role '.$roleNumber,
        'slug' => 'customer_detail_role_'.$owner->id.'_'.$roleNumber,
        'description' => 'Strict customer detail access role.',
        'is_system' => false,
        'is_default' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'is_active' => true,
    ]);

    $permissionIds = collect($permissionSlugs)
        ->map(function (string $slug): int {
            return (int) Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'group' => str($slug)->before('_')->before('.')->value(),
                    'name' => str($slug)->headline()->value(),
                    'description' => null,
                ],
            )->id;
        });

    $role->permissions()->sync($permissionIds);

    return $role->load('permissions');
}

/**
 * @param  list<string>  $rolePermissions
 * @param  list<string>  $directPermissions
 */
function customerDetailRbacMember(
    User $owner,
    array $rolePermissions,
    string $teamRole = 'member',
    array $directPermissions = [],
): User {
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $member = User::factory()->create([
        'role_id' => $employeeRole->id,
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);
    $companyRole = customerDetailRbacRole($owner, $rolePermissions);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => $teamRole,
        'company_role_id' => $companyRole->id,
        'permissions' => $directPermissions,
        'is_active' => true,
    ]);

    return $member;
}

function customerDetailRbacCustomer(User $owner): Customer
{
    return Customer::query()->create([
        'user_id' => $owner->id,
        'client_type' => 'individual',
        'first_name' => 'Strict',
        'last_name' => 'Customer',
        'email' => 'strict-customer-'.$owner->id.'@example.test',
        'description' => 'Confidential customer note.',
        'portal_access' => false,
        'is_active' => true,
        'is_vip' => false,
    ]);
}

/**
 * Create one record for every subordinate data family rendered by the customer detail page.
 */
function customerDetailRbacOperationalRecords(User $owner, Customer $customer): void
{
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Strict quote',
        'status' => 'draft',
        'subtotal' => 125,
        'total' => 125,
        'initial_deposit' => 0,
    ]);
    Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Strict job',
        'instructions' => 'Strict job instructions.',
        'status' => Work::STATUS_SCHEDULED,
        'start_date' => now()->addDay(),
        'is_completed' => false,
        'subtotal' => 200,
        'total' => 200,
    ]);
    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Strict request',
        'service_type' => 'Consultation',
    ]);
    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Strict task',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => now()->addDay(),
    ]);
    Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 75,
        'tax_total' => 0,
        'total' => 75,
        'paid_at' => now(),
    ]);
}

/**
 * @param  array<string, bool>  $expected
 */
function customerDetailRbacAssertCapabilities(TestResponse $response, array $expected): void
{
    foreach ($expected as $capability => $allowed) {
        $response->assertJsonPath('detailCapabilities.'.$capability, $allowed);
    }
}

function customerDetailRbacAssertOperationalDataHidden(TestResponse $response): void
{
    $payload = $response->json();

    expect(data_get($payload, 'customer.requests', []))->toBe([])
        ->and(data_get($payload, 'customer.quotes', []))->toBe([])
        ->and(data_get($payload, 'customer.works', []))->toBe([])
        ->and(data_get($payload, 'schedule.tasks', []))->toBe([])
        ->and(data_get($payload, 'schedule.upcomingJobs', []))->toBe([])
        ->and((int) data_get($payload, 'stats.requests', 0))->toBe(0)
        ->and((int) data_get($payload, 'stats.quotes', 0))->toBe(0)
        ->and((int) data_get($payload, 'stats.jobs', 0))->toBe(0)
        ->and((int) data_get($payload, 'stats.active_works', 0))->toBe(0)
        ->and(data_get($payload, 'sales', []))->toBe([])
        ->and(data_get($payload, 'salesSummary'))->toBeNull();
}

it('redacts notes and returns 403 for note updates when the role only views clients', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    $member = customerDetailRbacMember($owner, ['view_clients']);
    ActivityLog::record(
        $owner,
        $customer,
        'notes_updated',
        ['note' => 'Confidential timeline note.'],
        'Confidential timeline note.',
    );

    $response = $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonMissingPath('customer.description')
        ->assertJsonPath('canViewNotes', false)
        ->assertJsonPath('canManageNotes', false);

    expect($response->json('customerActivity.meta.available_types'))->not->toContain('notes')
        ->and(collect($response->json('customerActivity.data'))->pluck('type')->all())->not->toContain('notes');

    $this->actingAs($member)
        ->patchJson(route('customer.notes.update', $customer), [
            'description' => 'Unauthorized replacement note.',
        ])
        ->assertForbidden();

    expect($customer->refresh()->description)->toBe('Confidential customer note.');
});

it('lets a note reader see notes without allowing note changes', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    $member = customerDetailRbacMember($owner, ['view_clients', 'view_client_notes']);
    ActivityLog::record(
        $owner,
        $customer,
        'notes_updated',
        ['note' => 'Readable timeline note.'],
        'Readable timeline note.',
    );

    $response = $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonPath('customer.description', 'Confidential customer note.')
        ->assertJsonPath('canViewNotes', true)
        ->assertJsonPath('canManageNotes', false);

    expect($response->json('customerActivity.meta.available_types'))->toContain('notes')
        ->and(collect($response->json('customerActivity.data'))->pluck('type')->all())->toContain('notes');

    $this->actingAs($member)
        ->patchJson(route('customer.notes.update', $customer), [
            'description' => 'Reader must not write this note.',
        ])
        ->assertForbidden();

    expect($customer->refresh()->description)->toBe('Confidential customer note.');
});

it('lets a note manager read and update notes', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    $member = customerDetailRbacMember($owner, ['view_clients', 'manage_client_notes']);

    $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonPath('customer.description', 'Confidential customer note.')
        ->assertJsonPath('canViewNotes', true)
        ->assertJsonPath('canManageNotes', true);

    $this->actingAs($member)
        ->patchJson(route('customer.notes.update', $customer), [
            'description' => 'Authorized replacement note.',
        ])
        ->assertOk()
        ->assertJsonPath('customer.description', 'Authorized replacement note.');

    expect($customer->refresh()->description)->toBe('Authorized replacement note.');
    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'action' => 'notes_updated',
    ]);
});

it('returns 403 when a client editor tries to change notes through the general update', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    $member = customerDetailRbacMember($owner, ['view_clients', 'update_clients']);

    $this->actingAs($member)
        ->putJson(route('customer.update', $customer), [
            'client_type' => 'individual',
            'first_name' => 'Unauthorized',
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'description' => 'A changed note requires its own permission.',
            'portal_access' => false,
        ])
        ->assertForbidden();

    $customer->refresh();
    expect($customer->first_name)->toBe('Strict')
        ->and($customer->description)->toBe('Confidential customer note.');
});

it('hides every subordinate customer data family without its exact permission', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    customerDetailRbacOperationalRecords($owner, $customer);
    $member = customerDetailRbacMember($owner, ['view_clients']);

    $response = $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk();

    customerDetailRbacAssertCapabilities($response, [
        'requests' => false,
        'quotes' => false,
        'jobs' => false,
        'tasks' => false,
        'invoices' => false,
        'reservations' => false,
        'sales' => false,
    ]);
    customerDetailRbacAssertOperationalDataHidden($response);
});

it('keeps subordinate customer data hidden when permissions exist but features are disabled', function () {
    $owner = customerDetailRbacOwner([
        'requests' => false,
        'quotes' => false,
        'jobs' => false,
        'tasks' => false,
        'sales' => false,
        'sales_crm' => false,
    ]);
    $customer = customerDetailRbacCustomer($owner);
    customerDetailRbacOperationalRecords($owner, $customer);
    $member = customerDetailRbacMember($owner, [
        'view_clients',
        'view_prospects',
        'view_quotes',
        'view_jobs',
        'view_tasks',
        'sales.manage',
    ]);

    $response = $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk();

    customerDetailRbacAssertCapabilities($response, [
        'requests' => false,
        'quotes' => false,
        'jobs' => false,
        'tasks' => false,
        'sales' => false,
    ]);
    customerDetailRbacAssertOperationalDataHidden($response);
});

it('exposes each subordinate customer data family only when its feature and permission are both present', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    customerDetailRbacOperationalRecords($owner, $customer);
    $member = customerDetailRbacMember($owner, [
        'view_clients',
        'view_prospects',
        'view_quotes',
        'view_jobs',
        'view_tasks',
        'sales.manage',
    ]);

    $response = $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk();

    customerDetailRbacAssertCapabilities($response, [
        'requests' => true,
        'quotes' => true,
        'jobs' => true,
        'tasks' => true,
        'sales' => true,
    ]);
    $response
        ->assertJsonCount(1, 'customer.requests')
        ->assertJsonCount(1, 'customer.quotes')
        ->assertJsonCount(1, 'customer.works')
        ->assertJsonCount(1, 'schedule.tasks')
        ->assertJsonCount(1, 'schedule.upcomingJobs')
        ->assertJsonPath('stats.requests', 1)
        ->assertJsonPath('stats.quotes', 1)
        ->assertJsonPath('stats.jobs', 1)
        ->assertJsonPath('stats.active_works', 1)
        ->assertJsonCount(1, 'sales')
        ->assertJsonPath('salesSummary.count', 1);
});

it('does not let the legacy admin label or stale direct grants override the assigned company role', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    customerDetailRbacOperationalRecords($owner, $customer);
    ActivityLog::record(
        $owner,
        $customer,
        'notes_updated',
        ['note' => 'Legacy admin must not read this note.'],
        'Legacy admin must not read this note.',
    );
    $member = customerDetailRbacMember(
        $owner,
        ['view_clients'],
        'admin',
        ['view_client_notes', 'manage_client_notes', 'sales.manage'],
    );

    $response = $this->actingAs($member)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonMissingPath('customer.description')
        ->assertJsonPath('canViewNotes', false)
        ->assertJsonPath('canManageNotes', false)
        ->assertJsonPath('canLogSalesActivity', false)
        ->assertJsonPath('detailCapabilities.sales', false);

    expect($response->json('customerActivity.meta.available_types'))->not->toContain('notes')
        ->and(collect($response->json('customerActivity.data'))->pluck('type')->all())->not->toContain('notes')
        ->and($response->json('sales'))->toBe([])
        ->and($response->json('salesSummary'))->toBeNull();

    $this->actingAs($member)
        ->patchJson(route('customer.notes.update', $customer), [
            'description' => 'Legacy admin bypass attempt.',
        ])
        ->assertForbidden();

    expect($customer->refresh()->description)->toBe('Confidential customer note.');
});

it('keeps the dashboard calendar scoped by task and job permissions even for a legacy admin profile', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'title' => 'Restricted calendar task',
        'status' => Task::STATUS_TODO,
        'priority' => Task::PRIORITY_NORMAL,
        'due_date' => today(),
    ]);
    Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Restricted calendar job',
        'instructions' => 'Restricted calendar instructions.',
        'status' => Work::STATUS_SCHEDULED,
        'start_date' => today(),
        'is_completed' => false,
        'subtotal' => 200,
        'total' => 200,
    ]);

    $restrictedAdmin = customerDetailRbacMember($owner, ['view_clients'], 'admin');
    $this->actingAs($restrictedAdmin)
        ->get(route('tasks.calendar'))
        ->assertOk()
        ->assertDontSee('Restricted calendar task', false)
        ->assertDontSee('Restricted calendar job', false);

    $taskReaderAdmin = customerDetailRbacMember($owner, ['view_clients', 'view_tasks'], 'admin');
    $this->actingAs($taskReaderAdmin)
        ->get(route('tasks.calendar'))
        ->assertOk()
        ->assertSee('Restricted calendar task', false)
        ->assertDontSee('Restricted calendar job', false);
});

it('keeps service dashboard datasets scoped by exact permissions for every company role', function () {
    $owner = customerDetailRbacOwner();
    $customer = customerDetailRbacCustomer($owner);
    customerDetailRbacOperationalRecords($owner, $customer);

    $restrictedAdmin = customerDetailRbacMember($owner, ['view_clients'], 'admin');
    $this->actingAs($restrictedAdmin)
        ->getJson(route('dashboard', ['rbac_test' => 1]))
        ->assertOk()
        ->assertJsonPath('stats.tasks_total', 0)
        ->assertJsonCount(0, 'tasks')
        ->assertJsonCount(0, 'worksToday');

    $taskReaderAdmin = customerDetailRbacMember($owner, ['view_clients', 'view_tasks'], 'admin');
    $this->actingAs($taskReaderAdmin)
        ->getJson(route('dashboard', ['rbac_test' => 1]))
        ->assertOk()
        ->assertJsonPath('stats.tasks_total', 1)
        ->assertJsonCount(1, 'tasks')
        ->assertJsonCount(0, 'worksToday');
});
