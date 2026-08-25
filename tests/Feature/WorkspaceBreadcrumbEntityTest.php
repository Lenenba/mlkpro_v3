<?php

use App\Models\Customer;
use App\Models\PlanScan;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function workspaceBreadcrumbOwner(array $features = []): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_features' => $features,
        'selected_plan_key' => null,
    ]);
}

/**
 * @return array{0: User, 1: TeamMember}
 */
function workspaceBreadcrumbMember(
    User $owner,
    array $permissions,
    string $membershipRole = 'member',
    bool $active = true,
): array {
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $employee = User::factory()->withRole($employeeRole->id)->create([
        'company_features' => [],
        'selected_plan_key' => null,
    ]);
    $membership = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => $membershipRole,
        'permissions' => $permissions,
        'is_active' => $active,
    ]);

    return [$employee, $membership];
}

function workspaceBreadcrumbEntityUrl(string $type, array $query = []): string
{
    return route('workspace.breadcrumb-entities.index', [
        'type' => $type,
        ...$query,
    ]);
}

test('breadcrumb entity endpoint requires authentication and only exposes allowlisted types', function () {
    $this->getJson(workspaceBreadcrumbEntityUrl('customer'))
        ->assertUnauthorized();

    $this->actingAs(workspaceBreadcrumbOwner())
        ->getJson('/workspace/breadcrumb-entities/not-allowlisted')
        ->assertNotFound();
});

test('breadcrumb entity endpoint validates and normalizes its query contract', function () {
    $owner = workspaceBreadcrumbOwner();

    $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', ['q' => ['invalid']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');

    $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', ['q' => str_repeat('a', 121)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');

    foreach ([0, 16] as $limit) {
        $this->actingAs($owner)
            ->getJson(workspaceBreadcrumbEntityUrl('customer', ['limit' => $limit]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', ['q' => '  Acme  ', 'limit' => 1]))
        ->assertOk()
        ->assertJsonPath('query', 'Acme');
});

test('customer entities have a minimal stable payload with tenant isolation and pagination metadata', function () {
    $owner = workspaceBreadcrumbOwner();
    $otherOwner = workspaceBreadcrumbOwner();

    $alpha = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Tenant Alpha',
    ]);
    Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Tenant Beta',
    ]);
    $foreign = Customer::factory()->create([
        'user_id' => $otherOwner->id,
        'company_name' => 'Tenant Foreign',
    ]);

    $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', [
            'q' => 'Tenant',
            'limit' => 1,
        ]))
        ->assertOk()
        ->assertExactJson([
            'type' => 'customer',
            'query' => 'Tenant',
            'items' => [[
                'key' => 'customer-'.$alpha->id,
                'label' => 'Tenant Alpha',
                'href' => route('customer.show', ['customer' => $alpha->id]),
            ]],
            'has_more' => true,
        ]);

    $response = $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', ['q' => 'Tenant']))
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('has_more', false);

    expect(collect($response->json('items'))->pluck('key')->all())
        ->not->toContain('customer-'.$foreign->id);
});

test('customer entity search treats sql wildcard characters literally', function () {
    $owner = workspaceBreadcrumbOwner();
    $percent = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Literal % Customer',
    ]);
    $underscore = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Literal _ Customer',
    ]);
    Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Literal Plain Customer',
    ]);

    $percentResponse = $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', ['q' => '%']))
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect(data_get($percentResponse->json(), 'items.0.key'))
        ->toBe('customer-'.$percent->id);

    $underscoreResponse = $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('customer', ['q' => '_']))
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect(data_get($underscoreResponse->json(), 'items.0.key'))
        ->toBe('customer-'.$underscore->id);
});

test('task entities only expose tasks viewable by a non admin assignee', function () {
    $owner = workspaceBreadcrumbOwner(['tasks' => true]);
    [$employee, $membership] = workspaceBreadcrumbMember($owner, ['tasks.view']);
    [, $otherMembership] = workspaceBreadcrumbMember($owner, ['tasks.view']);

    $assigned = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $membership->id,
        'title' => 'Assigned breadcrumb task',
        'status' => Task::STATUS_TODO,
    ]);
    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $otherMembership->id,
        'title' => 'Another member task',
        'status' => Task::STATUS_TODO,
    ]);
    Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => null,
        'title' => 'Unassigned task',
        'status' => Task::STATUS_TODO,
    ]);

    $response = $this->actingAs($employee)
        ->getJson(workspaceBreadcrumbEntityUrl('task'))
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect($response->json('items'))->toBe([[
        'key' => 'task-'.$assigned->id,
        'label' => 'Assigned breadcrumb task',
        'href' => route('task.show', ['task' => $assigned->id]),
    ]]);
});

test('work entities only expose jobs assigned to a permitted team member', function () {
    $owner = workspaceBreadcrumbOwner(['jobs' => true]);
    [$employee, $membership] = workspaceBreadcrumbMember($owner, ['jobs.view']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $assigned = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Assigned breadcrumb job',
        'status' => Work::STATUS_SCHEDULED,
    ]);
    Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Unassigned breadcrumb job',
        'status' => Work::STATUS_SCHEDULED,
    ]);
    $assigned->teamMembers()->attach($membership->id, ['role' => 'technician']);

    $response = $this->actingAs($employee)
        ->getJson(workspaceBreadcrumbEntityUrl('work'))
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect($response->json('items'))->toBe([[
        'key' => 'work-'.$assigned->id,
        'label' => $assigned->number,
        'href' => route('work.show', ['work' => $assigned->id]),
    ]]);
});

test('employee entities keep own performance access scoped to the current active member', function () {
    $owner = workspaceBreadcrumbOwner([
        'performance' => true,
        'jobs' => true,
    ]);
    [$employee] = workspaceBreadcrumbMember($owner, ['jobs.view']);
    workspaceBreadcrumbMember($owner, ['jobs.view']);
    [, $inactiveMembership] = workspaceBreadcrumbMember($owner, ['jobs.view'], active: false);

    $response = $this->actingAs($employee)
        ->getJson(workspaceBreadcrumbEntityUrl('employee'))
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect($response->json('items'))->toBe([[
        'key' => 'employee-'.$employee->id,
        'label' => $employee->name,
        'href' => route('performance.employee.show', ['employee' => $employee->id]),
    ]])->and(collect($response->json('items'))->pluck('key')->all())
        ->not->toContain('employee-'.$inactiveMembership->user_id);
});

test('plan scan entities remain owner only and tenant scoped', function () {
    $owner = workspaceBreadcrumbOwner([
        'quotes' => true,
        'plan_scans' => true,
    ]);
    $otherOwner = workspaceBreadcrumbOwner([
        'quotes' => true,
        'plan_scans' => true,
    ]);
    [$employee] = workspaceBreadcrumbMember($owner, ['quotes.view']);
    $scan = PlanScan::query()->create([
        'user_id' => $owner->id,
        'job_title' => 'Owner plan scan',
        'plan_file_name' => 'owner-plan.pdf',
    ]);
    PlanScan::query()->create([
        'user_id' => $otherOwner->id,
        'job_title' => 'Foreign plan scan',
        'plan_file_name' => 'foreign-plan.pdf',
    ]);

    $this->actingAs($owner)
        ->getJson(workspaceBreadcrumbEntityUrl('plan_scan'))
        ->assertOk()
        ->assertExactJson([
            'type' => 'plan_scan',
            'query' => '',
            'items' => [[
                'key' => 'plan-scan-'.$scan->id,
                'label' => 'Owner plan scan',
                'href' => route('plan-scans.show', ['planScan' => $scan->id]),
            ]],
            'has_more' => false,
        ]);

    $this->actingAs($employee)
        ->getJson(workspaceBreadcrumbEntityUrl('plan_scan'))
        ->assertForbidden();
});
