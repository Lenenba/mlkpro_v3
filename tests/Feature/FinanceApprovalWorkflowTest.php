<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Services\WorkBillingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function financeApprovalRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function financeApprovalOwner(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'name' => 'Finance Owner',
        'email' => 'finance-owner-'.fake()->unique()->safeEmail(),
        'password' => 'password',
        'role_id' => financeApprovalRoleId('owner'),
        'company_name' => 'Finance Workspace',
        'company_type' => 'services',
        'currency_code' => 'CAD',
        'selected_plan_key' => 'starter',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'expenses' => true,
            'invoices' => true,
            'team_members' => true,
        ],
    ], $attributes));
}

function financeApprovalEmployee(array $attributes = []): User
{
    return User::query()->create(array_merge([
        'name' => 'Finance Employee',
        'email' => 'finance-employee-'.fake()->unique()->safeEmail(),
        'password' => 'password',
        'role_id' => financeApprovalRoleId('employee'),
        'company_type' => 'services',
        'currency_code' => 'CAD',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'expenses' => true,
            'invoices' => true,
            'team_members' => true,
        ],
    ], $attributes));
}

function financeApprovalTeamMember(User $owner, string $role, array $permissions, array $userAttributes = []): User
{
    $employee = financeApprovalEmployee(array_merge([
        'name' => 'Finance '.$role,
        'email' => 'finance-'.$role.'-'.fake()->unique()->safeEmail(),
    ], $userAttributes));

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => $role,
        'title' => ucfirst(str_replace('_', ' ', $role)),
        'permissions' => $permissions,
        'planning_rules' => null,
        'is_active' => true,
    ]);

    return $employee;
}

function financeApprovalCustomer(User $owner): Customer
{
    return Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'company_name' => 'Finance Customer',
        'email' => 'finance-customer-'.fake()->unique()->safeEmail(),
        'phone' => '+1 514 555 2002',
        'salutation' => 'Mr',
        'billing_same_as_physical' => false,
    ]);
}

function financeApprovalWork(User $owner, Customer $customer, array $overrides = []): Work
{
    return Work::query()->create(array_merge([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Finance work order',
        'instructions' => 'Prepare invoice workflow.',
        'status' => Work::STATUS_TO_SCHEDULE,
        'total' => 900,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('solo plans auto approve expenses immediately at creation time', function () {
    $owner = financeApprovalOwner([
        'selected_plan_key' => 'solo_essential',
        'company_features' => [
            'expenses' => true,
            'invoices' => true,
            'team_members' => false,
        ],
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('expense.store'), [
            'title' => 'Solo fuel refill',
            'category_key' => 'fuel',
            'supplier_name' => 'Station Centre',
            'reference_number' => 'SOLO-001',
            'tax_amount' => 2.50,
            'total' => 22.50,
            'expense_date' => now()->toDateString(),
            'status' => Expense::STATUS_DRAFT,
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.status', Expense::STATUS_APPROVED)
        ->assertJsonPath('expense.approved_by_user_id', $owner->id)
        ->assertJsonPath('expense.current_approver_role_key', null);

    $expense = Expense::query()->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_APPROVED)
        ->and(data_get($expense->meta, 'approval.policy_snapshot.auto_approved'))->toBeTrue();

    expect(ActivityLog::query()
        ->where('subject_type', $expense->getMorphClass())
        ->where('subject_id', $expense->id)
        ->pluck('action')
        ->all())->toBe(['created', 'auto_approved']);
});

test('team submitters are isolated from approval actions and escalations use configured roles', function () {
    $owner = financeApprovalOwner();
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
    ]);
    $approver = financeApprovalTeamMember($owner, 'sales_manager', [
        'expenses.view',
        'expenses.approve_high',
        'expenses.pay',
    ]);

    $response = $this->actingAs($submitter)
        ->postJson(route('expense.store'), [
            'title' => 'Escalated expense',
            'category_key' => 'software',
            'supplier_name' => 'Acme SaaS',
            'reference_number' => 'TEAM-1500',
            'subtotal' => 1500,
            'tax_amount' => 0,
            'total' => 1500,
            'expense_date' => now()->toDateString(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.status', Expense::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('expense.current_approver_role_key', 'sales_manager')
        ->assertJsonPath('expense.current_approval_level', 2);

    $expense = Expense::query()->latest('id')->firstOrFail();

    $this->actingAs($submitter)
        ->patchJson(route('expense.approve', $expense))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->actingAs($approver)
        ->patchJson(route('expense.approve', $expense))
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_APPROVED)
        ->assertJsonPath('expense.approved_by_user_id', $approver->id)
        ->assertJsonPath('expense.current_approver_role_key', null);
});

test('company settings can save finance approval thresholds and influence expense routing', function () {
    $owner = financeApprovalOwner([
        'company_name' => 'Threshold Workspace',
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
    ]);

    $this->actingAs($owner)
        ->putJson(route('settings.company.update'), [
            'company_name' => 'Threshold Workspace',
            'company_type' => 'services',
            'company_finance_settings' => [
                'expense' => [
                    'roles' => [
                        ['role_key' => 'admin', 'max_amount' => 300, 'approval_order' => 1],
                        ['role_key' => 'sales_manager', 'max_amount' => 1500, 'approval_order' => 2],
                    ],
                ],
                'invoice' => [
                    'roles' => [
                        ['role_key' => 'admin', 'max_amount' => 800, 'approval_order' => 1],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('company.company_finance_settings.expense.roles.0.role_key', 'admin')
        ->assertJsonPath('company.company_finance_settings.expense.roles.0.max_amount', 300)
        ->assertJsonPath('company.company_finance_settings.expense.roles.1.role_key', 'sales_manager');

    $this->actingAs($submitter)
        ->postJson(route('expense.store'), [
            'title' => 'Custom threshold expense',
            'category_key' => 'marketing',
            'supplier_name' => 'Creative Media',
            'reference_number' => 'CFG-400',
            'subtotal' => 400,
            'tax_amount' => 0,
            'total' => 400,
            'expense_date' => now()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('expense.status', Expense::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('expense.current_approver_role_key', 'sales_manager')
        ->assertJsonPath('expense.current_approval_level', 2);
});

test('invoice creation reuses the approval engine without breaking billing status', function () {
    $soloOwner = financeApprovalOwner([
        'selected_plan_key' => 'solo_pro',
        'company_features' => [
            'expenses' => true,
            'invoices' => true,
            'team_members' => false,
        ],
    ]);
    $soloCustomer = financeApprovalCustomer($soloOwner);
    $soloWork = financeApprovalWork($soloOwner, $soloCustomer, ['total' => 700]);

    $soloInvoice = app(WorkBillingService::class)->createInvoiceFromWork($soloWork, $soloOwner);

    expect($soloInvoice->status)->toBe('sent')
        ->and($soloInvoice->approval_status)->toBe('approved')
        ->and($soloInvoice->approved_by_user_id)->toBe($soloOwner->id);

    $teamOwner = financeApprovalOwner([
        'company_name' => 'Team Invoice Workspace',
    ]);
    $teamActor = financeApprovalTeamMember($teamOwner, 'admin', [
        'invoices.view',
        'invoices.create',
        'invoices.approve',
    ]);
    $teamCustomer = financeApprovalCustomer($teamOwner);
    $teamWork = financeApprovalWork($teamOwner, $teamCustomer, ['total' => 900]);

    $teamInvoice = app(WorkBillingService::class)->createInvoiceFromWork($teamWork, $teamActor);

    expect($teamInvoice->status)->toBe('sent')
        ->and($teamInvoice->approval_status)->toBe('submitted')
        ->and($teamInvoice->current_approver_role_key)->toBe('admin')
        ->and($teamInvoice->created_by_user_id)->toBe($teamActor->id);
});
