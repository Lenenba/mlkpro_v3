<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\FinanceApprovalRequestedNotification;
use App\Models\Work;
use App\Notifications\InvoiceAvailableNotification;
use App\Services\FinanceApprovalService;
use App\Services\WorkBillingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

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
                    'auto_approve_under_amount' => 450,
                    'roles' => [
                        ['role_key' => 'admin', 'max_amount' => 800, 'approval_order' => 1],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('company.company_finance_settings.expense.roles.0.role_key', 'admin')
        ->assertJsonPath('company.company_finance_settings.expense.roles.0.max_amount', 300)
        ->assertJsonPath('company.company_finance_settings.expense.roles.1.role_key', 'sales_manager')
        ->assertJsonPath('company.company_finance_settings.invoice.auto_approve_under_amount', 450);

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

test('team owners auto approve invoices they create and customer notifications stay enabled', function () {
    Notification::fake();

    $owner = financeApprovalOwner([
        'company_name' => 'Owner Invoice Workspace',
    ]);
    $customer = financeApprovalCustomer($owner);
    $work = financeApprovalWork($owner, $customer, ['total' => 650]);

    $invoice = app(WorkBillingService::class)->createInvoiceFromWork($work, $owner);

    expect($invoice->approval_status)->toBe(FinanceApprovalService::APPROVAL_STATUS_APPROVED)
        ->and($invoice->approved_by_user_id)->toBe($owner->id)
        ->and($invoice->current_approver_role_key)->toBeNull();

    Notification::assertSentTo($customer, InvoiceAvailableNotification::class);
});

test('team invoice approval blocks sending until approved and supports processed status', function () {
    Notification::fake();

    $owner = financeApprovalOwner([
        'company_name' => 'Invoice Approval Workspace',
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'invoices.view',
        'invoices.create',
    ]);
    $approver = financeApprovalTeamMember($owner, 'admin', [
        'invoices.view',
        'invoices.approve',
    ]);
    $customer = financeApprovalCustomer($owner);
    $work = financeApprovalWork($owner, $customer, ['total' => 900]);

    $this->actingAs($submitter)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::query()->where('work_id', $work->id)->latest('id')->firstOrFail();

    expect($invoice->approval_status)->toBe(FinanceApprovalService::APPROVAL_STATUS_SUBMITTED)
        ->and($invoice->created_by_user_id)->toBe($submitter->id);

    Notification::assertSentTo($approver, FinanceApprovalRequestedNotification::class);
    Notification::assertNotSentTo($customer, InvoiceAvailableNotification::class);

    $this->actingAs($submitter)
        ->patchJson(route('invoice.approve', $invoice))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['approval_status']);

    $this->actingAs($approver)
        ->postJson(route('invoice.send.email', $invoice))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['approval_status']);

    $this->actingAs($approver)
        ->patchJson(route('invoice.approve', $invoice), [
            'comment' => 'Finance review complete.',
        ])
        ->assertOk()
        ->assertJsonPath('invoice.approval_status', FinanceApprovalService::APPROVAL_STATUS_APPROVED)
        ->assertJsonPath('invoice.approved_by_user_id', $approver->id);

    $this->actingAs($approver)
        ->patchJson(route('invoice.process', $invoice), [
            'comment' => 'Processed for dispatch.',
        ])
        ->assertOk()
        ->assertJsonPath('invoice.approval_status', FinanceApprovalService::APPROVAL_STATUS_PROCESSED)
        ->assertJsonPath('invoice.processed_by_user_id', $approver->id);

    $this->actingAs($approver)
        ->postJson(route('invoice.send.email', $invoice))
        ->assertOk()
        ->assertJsonPath('invoice.approval_status', FinanceApprovalService::APPROVAL_STATUS_PROCESSED);

    Notification::assertSentTo($customer, InvoiceAvailableNotification::class);
});

test('invoice pending approvals notify the target role and appear in the finance inbox', function () {
    Notification::fake();

    $owner = financeApprovalOwner([
        'company_name' => 'Finance Inbox Workspace',
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
        'invoices.view',
        'invoices.create',
    ]);
    $approver = financeApprovalTeamMember($owner, 'admin', [
        'expenses.view',
        'expenses.approve',
        'invoices.view',
        'invoices.approve',
    ]);
    $customer = financeApprovalCustomer($owner);
    $work = financeApprovalWork($owner, $customer, ['total' => 900]);

    $this->actingAs($submitter)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $this->actingAs($submitter)
        ->postJson(route('expense.store'), [
            'title' => 'Inbox expense',
            'category_key' => 'software',
            'supplier_name' => 'Inbox Vendor',
            'reference_number' => 'INBOX-EXP-1',
            'subtotal' => 240,
            'tax_amount' => 0,
            'total' => 240,
            'expense_date' => now()->toDateString(),
        ])
        ->assertCreated();

    $invoice = Invoice::query()->where('work_id', $work->id)->latest('id')->firstOrFail();
    $expense = Expense::query()->where('reference_number', 'INBOX-EXP-1')->latest('id')->firstOrFail();

    Notification::assertSentTo($approver, FinanceApprovalRequestedNotification::class, function (FinanceApprovalRequestedNotification $notification) use ($invoice, $approver) {
        return $notification->invoice->is($invoice)
            && data_get($notification->toArray($approver), 'action_url') === route('invoice.show', $invoice);
    });

    $this->actingAs($approver)
        ->get(route('finance-approvals.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('FinanceApprovals/Index')
            ->where('stats.total_pending', 2)
            ->has('invoices.data', 1)
            ->has('expenses.data', 1)
            ->where('invoices.data.0.id', $invoice->id)
            ->where('expenses.data.0.id', $expense->id)
            ->where('filters.search', '')
        );
});

test('finance inbox search filters pending approvals across expenses and invoices', function () {
    $owner = financeApprovalOwner([
        'company_name' => 'Search Inbox Workspace',
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
        'invoices.view',
        'invoices.create',
    ]);
    $approver = financeApprovalTeamMember($owner, 'admin', [
        'expenses.view',
        'expenses.approve',
        'invoices.view',
        'invoices.approve',
    ]);
    $customer = financeApprovalCustomer($owner);
    $matchingWork = financeApprovalWork($owner, $customer, [
        'total' => 900,
        'job_title' => 'Northwind approval job',
    ]);
    $otherWork = financeApprovalWork($owner, $customer, [
        'total' => 900,
        'job_title' => 'Southwind follow-up job',
    ]);

    $this->actingAs($submitter)
        ->post(route('invoice.store-from-work', $matchingWork))
        ->assertRedirect();

    $this->actingAs($submitter)
        ->post(route('invoice.store-from-work', $otherWork))
        ->assertRedirect();

    $this->actingAs($submitter)
        ->postJson(route('expense.store'), [
            'title' => 'Northwind media spend',
            'category_key' => 'marketing',
            'supplier_name' => 'Northwind Ads',
            'reference_number' => 'SEARCH-EXP-1',
            'subtotal' => 180,
            'tax_amount' => 0,
            'total' => 180,
            'expense_date' => now()->toDateString(),
        ])
        ->assertCreated();

    $this->actingAs($submitter)
        ->postJson(route('expense.store'), [
            'title' => 'General office software',
            'category_key' => 'software',
            'supplier_name' => 'Backoffice Tools',
            'reference_number' => 'SEARCH-EXP-2',
            'subtotal' => 95,
            'tax_amount' => 0,
            'total' => 95,
            'expense_date' => now()->toDateString(),
        ])
        ->assertCreated();

    $matchingInvoice = Invoice::query()->where('work_id', $matchingWork->id)->latest('id')->firstOrFail();
    $matchingExpense = Expense::query()->where('reference_number', 'SEARCH-EXP-1')->latest('id')->firstOrFail();

    $this->actingAs($approver)
        ->get(route('finance-approvals.index', ['search' => 'Northwind']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('FinanceApprovals/Index')
            ->where('filters.search', 'Northwind')
            ->where('stats.total_pending', 2)
            ->where('stats.expenses_pending', 1)
            ->where('stats.invoices_pending', 1)
            ->has('expenses.data', 1)
            ->has('invoices.data', 1)
            ->where('expenses.data.0.id', $matchingExpense->id)
            ->where('invoices.data.0.id', $matchingInvoice->id)
        );
});

test('finance inbox paginates expense approvals for progressive loading', function () {
    $owner = financeApprovalOwner([
        'company_name' => 'Paged Inbox Workspace',
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
    ]);
    $approver = financeApprovalTeamMember($owner, 'admin', [
        'expenses.view',
        'expenses.approve',
    ]);

    foreach (range(1, 13) as $index) {
        $this->actingAs($submitter)
            ->postJson(route('expense.store'), [
                'title' => "Paged expense {$index}",
                'category_key' => 'software',
                'supplier_name' => 'Paged Vendor',
                'reference_number' => "PAGE-EXP-{$index}",
                'subtotal' => 80 + $index,
                'tax_amount' => 0,
                'total' => 80 + $index,
                'expense_date' => now()->subDays($index)->toDateString(),
            ])
            ->assertCreated();
    }

    $this->actingAs($approver)
        ->get(route('finance-approvals.index', ['expense_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('FinanceApprovals/Index')
            ->where('stats.expenses_pending', 13)
            ->where('expenses.current_page', 2)
            ->where('expenses.last_page', 2)
            ->where('expenses.total', 13)
            ->has('expenses.data', 1)
            ->where('expenses.data.0.reference_number', 'PAGE-EXP-13')
        );
});

test('finance inbox api exposes searchable approval payloads', function () {
    $owner = financeApprovalOwner([
        'company_name' => 'Finance API Workspace',
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'expenses.view',
        'expenses.create',
        'expenses.edit',
        'invoices.view',
        'invoices.create',
    ]);
    $approver = financeApprovalTeamMember($owner, 'admin', [
        'expenses.view',
        'expenses.approve',
        'invoices.view',
        'invoices.approve',
    ]);
    $customer = financeApprovalCustomer($owner);
    $work = financeApprovalWork($owner, $customer, [
        'total' => 900,
        'job_title' => 'Northwind finance API job',
    ]);

    $this->actingAs($submitter)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $this->actingAs($submitter)
        ->postJson(route('expense.store'), [
            'title' => 'Northwind API expense',
            'category_key' => 'software',
            'supplier_name' => 'Northwind Vendor',
            'reference_number' => 'API-INBOX-EXP-1',
            'subtotal' => 240,
            'tax_amount' => 0,
            'total' => 240,
            'expense_date' => now()->toDateString(),
        ])
        ->assertCreated();

    Sanctum::actingAs($approver);

    $this->getJson('/api/v1/finance-approvals?search=Northwind')
        ->assertOk()
        ->assertJsonPath('filters.search', 'Northwind')
        ->assertJsonPath('stats.total_pending', 2)
        ->assertJsonPath('stats.expenses_pending', 1)
        ->assertJsonPath('stats.invoices_pending', 1)
        ->assertJsonCount(1, 'expenses.data')
        ->assertJsonCount(1, 'invoices.data')
        ->assertJsonPath('expenses.data.0.title', 'Northwind API expense')
        ->assertJsonPath('invoices.data.0.work.job_title', 'Northwind finance API job');
});

test('team invoice auto approval under threshold can stay soft when configured', function () {
    Notification::fake();

    $owner = financeApprovalOwner([
        'company_name' => 'Soft Threshold Workspace',
        'company_finance_settings' => [
            'expense' => [
                'roles' => [
                    ['role_key' => 'admin', 'max_amount' => 1000, 'approval_order' => 1],
                    ['role_key' => 'sales_manager', 'max_amount' => 5000, 'approval_order' => 2],
                ],
            ],
            'invoice' => [
                'auto_approve_under_amount' => 1000,
                'roles' => [
                    ['role_key' => 'admin', 'max_amount' => 2500, 'approval_order' => 1],
                    ['role_key' => 'sales_manager', 'max_amount' => 10000, 'approval_order' => 2],
                ],
            ],
        ],
    ]);
    $submitter = financeApprovalTeamMember($owner, 'member', [
        'invoices.view',
        'invoices.create',
    ]);
    $approver = financeApprovalTeamMember($owner, 'admin', [
        'invoices.view',
        'invoices.approve',
    ]);
    $customer = financeApprovalCustomer($owner);
    $work = financeApprovalWork($owner, $customer, ['total' => 900]);

    $this->actingAs($submitter)
        ->post(route('invoice.store-from-work', $work))
        ->assertRedirect();

    $invoice = Invoice::query()->where('work_id', $work->id)->latest('id')->firstOrFail();

    expect($invoice->approval_status)->toBe(FinanceApprovalService::APPROVAL_STATUS_APPROVED)
        ->and($invoice->current_approver_role_key)->toBeNull()
        ->and($invoice->approved_by_user_id)->toBeNull()
        ->and(data_get($invoice->approval_meta, 'approval_policy_snapshot.auto_approved_reason'))->toBe('under_threshold');

    Notification::assertSentTo($customer, InvoiceAvailableNotification::class);
    Notification::assertNotSentTo($approver, FinanceApprovalRequestedNotification::class);
});
