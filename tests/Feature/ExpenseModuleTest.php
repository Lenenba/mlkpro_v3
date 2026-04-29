<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Invoice;
use App\Models\PettyCashAccount;
use App\Models\PettyCashClosure;
use App\Models\PettyCashMovement;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function expenseRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function expenseOwner(array $featureOverrides = [], array $attributes = []): User
{
    return User::query()->create(array_merge([
        'name' => 'Expense Owner',
        'email' => 'expense-owner-'.fake()->unique()->safeEmail(),
        'password' => 'password',
        'role_id' => expenseRoleId('owner'),
        'company_type' => 'services',
        'currency_code' => 'CAD',
        'onboarding_completed_at' => now(),
        'company_features' => array_replace([
            'expenses' => true,
        ], $featureOverrides),
    ], $attributes));
}

function expenseEmployee(array $featureOverrides = [], array $attributes = []): User
{
    return User::query()->create(array_merge([
        'name' => 'Expense Employee',
        'email' => 'expense-employee-'.fake()->unique()->safeEmail(),
        'password' => 'password',
        'role_id' => expenseRoleId('employee'),
        'company_type' => 'services',
        'currency_code' => 'CAD',
        'onboarding_completed_at' => now(),
        'company_features' => array_replace([
            'expenses' => true,
        ], $featureOverrides),
    ], $attributes));
}

function expenseTeamMember(User $owner, array $attributes = []): TeamMember
{
    $memberUser = expenseEmployee([], array_merge([
        'name' => 'Expense Team Member',
        'email' => 'expense-member-'.fake()->unique()->safeEmail(),
    ], $attributes['user'] ?? []));

    unset($attributes['user']);

    return TeamMember::query()->create(array_merge([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'title' => 'Field operator',
        'permissions' => [],
        'planning_rules' => null,
        'is_active' => true,
    ], $attributes));
}

function seedExpense(User $owner, array $overrides = []): Expense
{
    return Expense::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Fuel refill',
        'category_key' => 'fuel',
        'supplier_name' => 'Station Nord',
        'reference_number' => 'EXP-001',
        'currency_code' => 'CAD',
        'subtotal' => 42.00,
        'tax_amount' => 3.00,
        'total' => 45.00,
        'expense_date' => now()->toDateString(),
        'due_date' => now()->addDays(3)->toDateString(),
        'payment_method' => 'card',
        'status' => Expense::STATUS_DUE,
        'reimbursable' => false,
        'is_recurring' => false,
    ], $overrides));
}

function expenseCustomerRecord(User $owner, array $overrides = []): Customer
{
    return Customer::query()->create(array_merge([
        'user_id' => $owner->id,
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'company_name' => 'Northwind Studio',
        'email' => 'expense-customer-'.fake()->unique()->safeEmail(),
        'phone' => '+1 514 555 1001',
        'salutation' => 'Mr',
        'billing_same_as_physical' => false,
    ], $overrides));
}

function expenseWorkRecord(User $owner, Customer $customer, array $overrides = []): Work
{
    return Work::query()->create(array_merge([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Spring cleanup',
        'instructions' => 'Clean and prepare the full site.',
        'status' => Work::STATUS_TO_SCHEDULE,
    ], $overrides));
}

function expenseSaleRecord(User $owner, Customer $customer, array $overrides = []): Sale
{
    return Sale::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 120,
        'tax_total' => 18,
        'total' => 138,
    ], $overrides));
}

function expenseInvoiceRecord(User $owner, Customer $customer, Work $work, array $overrides = []): Invoice
{
    return Invoice::query()->create(array_merge([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'draft',
        'total' => 225,
    ], $overrides));
}

function expenseCampaignRecord(User $owner, array $overrides = []): Campaign
{
    return Campaign::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Spring Reactivation',
        'type' => Campaign::TYPE_PROMOTION,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ], $overrides));
}

function fakeExpenseScanOpenAi(array $payload, string $model = 'gpt-4.1-mini'): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-expense-scan',
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 900,
                'completion_tokens' => 140,
                'total_tokens' => 1040,
            ],
        ], 200),
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('expense index is unavailable when expenses feature is disabled', function () {
    $owner = expenseOwner([
        'expenses' => false,
    ]);

    $this->actingAs($owner)
        ->getJson(route('expense.index'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');
});

test('owner can open expense index and receive expense data', function () {
    $owner = expenseOwner();
    seedExpense($owner);

    $this->actingAs($owner)
        ->get(route('expense.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Expense/Index')
            ->where('count', 1)
            ->where('stats.total', 1)
            ->has('expenses.data', 1)
            ->where('tenantCurrencyCode', 'CAD')
        );
});

test('expense index exposes period recap for the selected month', function () {
    $this->travelTo(Carbon::parse('2026-04-28 12:00:00'));

    $owner = expenseOwner([
        'team_members' => true,
    ]);
    $member = expenseTeamMember($owner);

    seedExpense($owner, [
        'title' => 'CRM renewal',
        'category_key' => 'software',
        'supplier_name' => 'Acme SaaS',
        'subtotal' => 100,
        'tax_amount' => 0,
        'total' => 100,
        'expense_date' => '2026-04-10',
        'paid_date' => '2026-04-11',
        'status' => Expense::STATUS_PAID,
        'payment_method' => 'card',
        'team_member_id' => $member->id,
    ]);
    seedExpense($owner, [
        'title' => 'Fuel advance',
        'category_key' => 'fuel',
        'supplier_name' => 'Station Nord',
        'subtotal' => 50,
        'tax_amount' => 0,
        'total' => 50,
        'expense_date' => '2026-04-15',
        'status' => Expense::STATUS_PENDING_APPROVAL,
        'reimbursable' => true,
        'reimbursement_status' => Expense::REIMBURSEMENT_STATUS_PENDING,
        'payment_method' => 'cash',
    ]);
    seedExpense($owner, [
        'title' => 'March fuel',
        'category_key' => 'fuel',
        'supplier_name' => 'Station Nord',
        'subtotal' => 40,
        'tax_amount' => 0,
        'total' => 40,
        'expense_date' => '2026-03-12',
        'status' => Expense::STATUS_PAID,
        'paid_date' => '2026-03-14',
    ]);
    seedExpense($owner, [
        'title' => 'Cancelled charge',
        'total' => 999,
        'expense_date' => '2026-04-20',
        'status' => Expense::STATUS_CANCELLED,
    ]);

    $this->actingAs($owner)
        ->getJson(route('expense.index', ['recap_period' => 'month']))
        ->assertOk()
        ->assertJsonPath('periodRecap.period.key', 'month')
        ->assertJsonPath('periodRecap.period.start', '2026-04-01')
        ->assertJsonPath('periodRecap.period.end', '2026-04-30')
        ->assertJsonPath('periodRecap.kpis.total_spent', 150)
        ->assertJsonPath('periodRecap.kpis.previous_total_spent', 40)
        ->assertJsonPath('periodRecap.kpis.paid_total', 100)
        ->assertJsonPath('periodRecap.kpis.pending_approval_count', 1)
        ->assertJsonPath('periodRecap.kpis.reimbursement_total', 50)
        ->assertJsonPath('periodRecap.kpis.expense_count', 2)
        ->assertJsonPath('periodRecap.breakdowns.categories.0.key', 'software')
        ->assertJsonPath('periodRecap.breakdowns.payment_methods.0.key', 'card')
        ->assertJsonPath('periodRecap.alerts.0.key', 'missing_receipts');

    $this->travelBack();
});

test('expense index exposes a default petty cash account', function () {
    $owner = expenseOwner();

    $this->actingAs($owner)
        ->getJson(route('expense.index'))
        ->assertOk()
        ->assertJsonPath('pettyCash.account.currency_code', 'CAD')
        ->assertJsonPath('pettyCash.account.current_balance', 0)
        ->assertJsonPath('pettyCash.canCreate', true)
        ->assertJsonPath('pettyCash.canPost', true);

    expect(PettyCashAccount::query()->where('user_id', $owner->id)->count())->toBe(1);
});

test('owner can manage petty cash movements without affecting balance for drafts', function () {
    $this->travelTo(Carbon::parse('2026-04-28 12:00:00'));

    $owner = expenseOwner([
        'team_members' => true,
    ]);
    $member = expenseTeamMember($owner);
    $expense = seedExpense($owner, [
        'title' => 'Parking receipt',
        'total' => 25,
        'expense_date' => '2026-04-28',
    ]);

    $fundingId = $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_FUNDING,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => 200,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $owner->id,
            'note' => 'Initial cash funding',
        ])
        ->assertCreated()
        ->assertJsonPath('pettyCash.account.current_balance', 200)
        ->json('movement.id');

    expect($fundingId)->not->toBeNull();

    $draftId = $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => PettyCashMovement::STATUS_DRAFT,
            'amount' => 25,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $owner->id,
            'team_member_id' => $member->id,
            'expense_id' => $expense->id,
            'requires_receipt' => true,
            'receipt_attached' => false,
            'note' => 'Parking paid from cashbox',
        ])
        ->assertCreated()
        ->assertJsonPath('pettyCash.account.current_balance', 200)
        ->assertJsonPath('movement.status', PettyCashMovement::STATUS_DRAFT)
        ->json('movement.id');

    $this->actingAs($owner)
        ->patchJson(route('expense.petty-cash.movements.post', $draftId))
        ->assertOk()
        ->assertJsonPath('pettyCash.account.current_balance', 175)
        ->assertJsonPath('movement.status', PettyCashMovement::STATUS_POSTED)
        ->assertJsonPath('movement.balance_delta', -25);

    $this->actingAs($owner)
        ->patchJson(route('expense.petty-cash.movements.void', $draftId), [
            'void_reason' => 'Receipt duplicated in main expense',
        ])
        ->assertOk()
        ->assertJsonPath('pettyCash.account.current_balance', 200)
        ->assertJsonPath('movement.status', PettyCashMovement::STATUS_VOIDED);

    expect(PettyCashMovement::query()->where('user_id', $owner->id)->count())->toBe(2)
        ->and(PettyCashMovement::query()->where('status', PettyCashMovement::STATUS_VOIDED)->count())->toBe(1);

    $this->travelBack();
});

test('petty cash controls enforce receipt thresholds and adjustment permission', function () {
    $owner = expenseOwner([
        'team_members' => true,
    ]);
    $cashier = expenseTeamMember($owner, [
        'permissions' => ['expenses.view', 'expenses.pay'],
    ]);

    $this->actingAs($owner)
        ->patchJson(route('expense.petty-cash.account.update'), [
            'responsible_user_id' => $owner->id,
            'low_balance_threshold' => 150,
            'receipt_required_above' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('pettyCash.account.low_balance_threshold', 150)
        ->assertJsonPath('pettyCash.account.receipt_required_above', 50);

    $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => 75,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $owner->id,
            'note' => 'Field fuel',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('receipt');

    $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => 75,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $owner->id,
            'receipt_attached' => true,
            'note' => 'Field fuel',
        ])
        ->assertCreated()
        ->assertJsonPath('movement.requires_receipt', true)
        ->assertJsonPath('movement.accounting_event', 'petty_cash_expense_posted');

    $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_ADJUSTMENT,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => -5,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $owner->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    $this->actingAs($cashier->user)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_ADJUSTMENT,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => -5,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $cashier->user_id,
            'note' => 'Cash count correction',
        ])
        ->assertForbidden();
});

test('petty cash closures reconcile and lock closed periods until reopened', function () {
    $this->travelTo(Carbon::parse('2026-04-28 12:00:00'));

    $owner = expenseOwner();

    $fundingId = $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_FUNDING,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => 200,
            'movement_date' => '2026-04-01',
            'responsible_user_id' => $owner->id,
            'note' => 'Monthly funding',
        ])
        ->assertCreated()
        ->json('movement.id');

    $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => 25,
            'movement_date' => '2026-04-12',
            'responsible_user_id' => $owner->id,
            'note' => 'Parking',
        ])
        ->assertCreated();

    $closureId = $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.closures.store'), [
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'counted_balance' => 175,
            'status' => PettyCashClosure::STATUS_CLOSED,
            'comment' => 'Cash counted',
        ])
        ->assertCreated()
        ->assertJsonPath('closure.expected_balance', 175)
        ->assertJsonPath('closure.counted_balance', 175)
        ->assertJsonPath('closure.difference', 0)
        ->assertJsonPath('pettyCash.reconciliation.closure.status', PettyCashClosure::STATUS_CLOSED)
        ->json('closure.id');

    $this->actingAs($owner)
        ->patchJson(route('expense.petty-cash.movements.void', $fundingId), [
            'void_reason' => 'Trying to modify closed period',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('movement_date');

    $this->actingAs($owner)
        ->patchJson(route('expense.petty-cash.closures.reopen', $closureId), [
            'comment' => 'Correction needed',
        ])
        ->assertOk()
        ->assertJsonPath('closure.status', PettyCashClosure::STATUS_REOPENED);

    $this->actingAs($owner)
        ->patchJson(route('expense.petty-cash.movements.void', $fundingId), [
            'void_reason' => 'Funding duplicated',
        ])
        ->assertOk()
        ->assertJsonPath('movement.status', PettyCashMovement::STATUS_VOIDED);

    $this->travelBack();
});

test('petty cash export includes movement reconciliation context', function () {
    $owner = expenseOwner();
    $expense = seedExpense($owner, [
        'title' => 'Taxi receipt',
        'total' => 30,
        'expense_date' => '2026-04-28',
    ]);

    $this->actingAs($owner)
        ->postJson(route('expense.petty-cash.movements.store'), [
            'type' => PettyCashMovement::TYPE_EXPENSE,
            'status' => PettyCashMovement::STATUS_POSTED,
            'amount' => 30,
            'movement_date' => '2026-04-28',
            'responsible_user_id' => $owner->id,
            'expense_id' => $expense->id,
            'receipt_attached' => true,
            'note' => 'Taxi from job site',
        ])
        ->assertCreated();

    $response = $this->actingAs($owner)
        ->get(route('expense.petty-cash.export'));

    $response->assertOk();
    $content = $response->streamedContent();

    expect($content)
        ->toContain('linked_expense_id')
        ->toContain('Taxi receipt')
        ->toContain('Taxi from job site')
        ->toContain('petty_cash_expense_posted');
});

test('owner can create an expense with an attachment', function () {
    Storage::fake('public');

    $owner = expenseOwner();

    $response = $this->actingAs($owner)
        ->post(route('expense.store'), [
            'title' => 'Software subscription',
            'category_key' => 'software',
            'supplier_name' => 'Acme SaaS',
            'reference_number' => 'INV-900',
            'tax_amount' => 1.50,
            'total' => 11.50,
            'expense_date' => now()->toDateString(),
            'payment_method' => 'card',
            'status' => Expense::STATUS_PAID,
            'attachments' => [
                UploadedFile::fake()->create('receipt.pdf', 250, 'application/pdf'),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Expense created successfully.');

    $expense = Expense::query()->where('user_id', $owner->id)->latest('id')->first();
    $attachment = ExpenseAttachment::query()->where('expense_id', $expense?->id)->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->category_key)->toBe('software')
        ->and($expense->status)->toBe(Expense::STATUS_PAID)
        ->and($expense->paid_date)->not->toBeNull()
        ->and($attachment)->not->toBeNull()
        ->and($attachment->original_name)->toBe('receipt.pdf');

    Storage::disk('public')->assertExists($attachment->path);
});

test('owner can create a manual expense with a linked petty cash movement', function () {
    Storage::fake('public');

    $owner = expenseOwner();

    $this->actingAs($owner)
        ->post(route('expense.store'), [
            'title' => 'Field parking',
            'category_key' => 'travel',
            'supplier_name' => 'Downtown Parking',
            'tax_amount' => 0,
            'total' => 18.50,
            'expense_date' => '2026-04-28',
            'payment_method' => 'cash',
            'status' => Expense::STATUS_PAID,
            'attachments' => [
                UploadedFile::fake()->image('parking-receipt.jpg'),
            ],
            'petty_cash_create' => true,
            'petty_cash_status' => PettyCashMovement::STATUS_POSTED,
            'petty_cash_responsible_user_id' => $owner->id,
            'petty_cash_note' => 'Paid from the field cashbox',
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated()
        ->assertJsonPath('expense.title', 'Field parking')
        ->assertJsonPath('pettyCashMovement.type', PettyCashMovement::TYPE_EXPENSE)
        ->assertJsonPath('pettyCashMovement.status', PettyCashMovement::STATUS_POSTED)
        ->assertJsonPath('pettyCashMovement.amount', 18.5)
        ->assertJsonPath('pettyCashMovement.receipt_attached', true)
        ->assertJsonPath('pettyCashMovement.accounting_event', 'petty_cash_expense_posted');

    $expense = Expense::query()->latest('id')->first();
    $movement = PettyCashMovement::query()->latest('id')->first();
    $account = PettyCashAccount::query()->where('user_id', $owner->id)->first();

    expect($expense)->not->toBeNull()
        ->and($movement)->not->toBeNull()
        ->and((int) $movement->expense_id)->toBe((int) $expense->id)
        ->and($movement->receipt_attached)->toBeTrue()
        ->and(data_get($movement->meta, 'source'))->toBe('expense_manual_create')
        ->and($account?->current_balance)->toBe('-18.50');
});

test('owner can create a reimbursable recurring expense linked to a team member', function () {
    $owner = expenseOwner([
        'team_members' => true,
    ]);
    $member = expenseTeamMember($owner);

    $response = $this->actingAs($owner)
        ->postJson(route('expense.store'), [
            'title' => 'Monthly mileage stipend',
            'category_key' => 'reimbursement',
            'supplier_name' => 'Internal reimbursement',
            'reference_number' => 'REIMB-100',
            'subtotal' => 85.00,
            'tax_amount' => 0,
            'total' => 85.00,
            'expense_date' => '2026-04-14',
            'due_date' => '2026-04-16',
            'status' => Expense::STATUS_DRAFT,
            'reimbursable' => true,
            'team_member_id' => $member->id,
            'is_recurring' => true,
            'recurrence_frequency' => Expense::RECURRENCE_FREQUENCY_MONTHLY,
            'recurrence_interval' => 1,
            'recurrence_ends_at' => '2026-12-14',
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.reimbursable', true)
        ->assertJsonPath('expense.team_member_id', $member->id)
        ->assertJsonPath('expense.reimbursement_status', Expense::REIMBURSEMENT_STATUS_PENDING)
        ->assertJsonPath('expense.is_recurring', true)
        ->assertJsonPath('expense.recurrence_frequency', Expense::RECURRENCE_FREQUENCY_MONTHLY)
        ->assertJsonPath('expense.recurrence_next_date', '2026-05-14T00:00:00.000000Z');

    $expense = Expense::query()->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->team_member_id)->toBe($member->id)
        ->and($expense->reimbursement_status)->toBe(Expense::REIMBURSEMENT_STATUS_PENDING)
        ->and($expense->recurrence_next_date?->toDateString())->toBe('2026-05-14');
});

test('another owner cannot read a foreign expense', function () {
    $owner = expenseOwner();
    $intruder = expenseOwner();
    $expense = seedExpense($owner);

    $this->actingAs($intruder)
        ->getJson(route('expense.show', $expense))
        ->assertForbidden();
});

test('employee cannot create expenses even when feature flag is enabled', function () {
    $employee = expenseEmployee();

    $this->actingAs($employee)
        ->postJson(route('expense.store'), [
            'title' => 'Office supplies',
            'total' => 18.00,
            'expense_date' => now()->toDateString(),
            'status' => Expense::STATUS_DRAFT,
        ])
        ->assertForbidden();
});

test('owner can create an expense draft from ai invoice scan', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.expense_scan_model', 'gpt-4.1-mini');

    fakeExpenseScanOpenAi([
        'document_type' => 'invoice',
        'title' => 'Acme software invoice',
        'supplier_name' => 'Acme SaaS',
        'reference_number' => 'INV-204',
        'expense_date' => '2026-04-12',
        'due_date' => '2026-04-19',
        'currency_code' => 'CAD',
        'subtotal' => 100,
        'tax_amount' => 15,
        'total' => 115,
        'suggested_category' => 'software',
        'description' => 'Monthly CRM subscription',
        'assumptions' => [
            'Detected as a software subscription invoice.',
        ],
        'review_flags' => [],
        'confidence' => [
            'overall' => 92,
            'supplier' => 95,
            'amounts' => 91,
            'dates' => 88,
            'category' => 90,
        ],
    ]);

    $owner = expenseOwner([
        'assistant' => true,
    ]);

    $response = $this->actingAs($owner)
        ->post(route('expense.scan-ai'), [
            'document' => UploadedFile::fake()->create('acme-invoice.pdf', 240, 'application/pdf'),
            'note' => 'Uploaded from finance desk.',
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.status', Expense::STATUS_DRAFT)
        ->assertJsonPath('expense.supplier_name', 'Acme SaaS')
        ->assertJsonPath('expense.category_key', 'software')
        ->assertJsonPath('expense.ai_intake.review_required', false);

    $expense = Expense::query()->latest('id')->first();
    $attachment = ExpenseAttachment::query()->where('expense_id', $expense?->id)->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_DRAFT)
        ->and($expense->total)->toBe('115.00')
        ->and(data_get($expense->meta, 'ai_intake.normalized.reference_number'))->toBe('INV-204')
        ->and(data_get($expense->meta, 'ai_intake.review_required'))->toBeFalse()
        ->and($attachment)->not->toBeNull();

    Storage::disk('public')->assertExists($attachment->path);
});

test('ai expense scan can also create a linked petty cash movement', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.expense_scan_model', 'gpt-4.1-mini');

    fakeExpenseScanOpenAi([
        'document_type' => 'receipt',
        'title' => 'Parking receipt',
        'supplier_name' => 'Downtown Parking',
        'reference_number' => 'PARK-204',
        'expense_date' => '2026-04-28',
        'due_date' => null,
        'currency_code' => 'CAD',
        'subtotal' => 20,
        'tax_amount' => 3,
        'total' => 23,
        'suggested_category' => 'travel',
        'description' => 'Parking near the job site',
        'assumptions' => [],
        'review_flags' => [],
        'confidence' => [
            'overall' => 94,
            'supplier' => 95,
            'amounts' => 94,
            'dates' => 90,
            'category' => 88,
        ],
    ]);

    $owner = expenseOwner([
        'assistant' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('expense.scan-ai'), [
            'document' => UploadedFile::fake()->image('parking-receipt.jpg'),
            'note' => 'Paid from the field cashbox.',
            'petty_cash_create' => true,
            'petty_cash_status' => PettyCashMovement::STATUS_POSTED,
            'petty_cash_responsible_user_id' => $owner->id,
            'petty_cash_note' => 'Parking paid from cashbox',
        ], [
            'Accept' => 'application/json',
        ])
        ->assertCreated()
        ->assertJsonPath('expense.title', 'Parking receipt')
        ->assertJsonPath('pettyCashMovement.type', PettyCashMovement::TYPE_EXPENSE)
        ->assertJsonPath('pettyCashMovement.status', PettyCashMovement::STATUS_POSTED)
        ->assertJsonPath('pettyCashMovement.amount', 23)
        ->assertJsonPath('pettyCashMovement.receipt_attached', true)
        ->assertJsonPath('pettyCashMovement.accounting_event', 'petty_cash_expense_posted');

    $expense = Expense::query()->latest('id')->first();
    $movement = PettyCashMovement::query()->latest('id')->first();
    $account = PettyCashAccount::query()->where('user_id', $owner->id)->first();

    expect($expense)->not->toBeNull()
        ->and($movement)->not->toBeNull()
        ->and((int) $movement->expense_id)->toBe((int) $expense->id)
        ->and($movement->receipt_attached)->toBeTrue()
        ->and($account?->current_balance)->toBe('-23.00');
});

test('ai expense scan falls back to review mode when openai is unavailable', function () {
    Storage::fake('public');

    config()->set('services.openai.key', null);

    $owner = expenseOwner([
        'assistant' => true,
    ]);

    $response = $this->actingAs($owner)
        ->post(route('expense.scan-ai'), [
            'document' => UploadedFile::fake()->create('paper-receipt.pdf', 120, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.status', Expense::STATUS_REVIEW_REQUIRED)
        ->assertJsonPath('expense.ai_intake.review_required', true)
        ->assertJsonPath('expense.ai_intake.status', 'skipped');

    $expense = Expense::query()->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_REVIEW_REQUIRED)
        ->and(data_get($expense->meta, 'ai_intake.normalized.category_key'))->toBe('other');
});

test('ai expense scan flags potential duplicates on the account', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.expense_scan_model', 'gpt-4.1-mini');

    fakeExpenseScanOpenAi([
        'document_type' => 'invoice',
        'title' => 'Acme SaaS - monthly subscription',
        'supplier_name' => 'Acme SaaS',
        'reference_number' => 'INV-204',
        'expense_date' => now()->toDateString(),
        'due_date' => now()->addDays(15)->toDateString(),
        'currency_code' => 'CAD',
        'subtotal' => 100,
        'tax_amount' => 15,
        'total' => 115,
        'suggested_category' => 'software',
        'description' => 'Monthly software subscription',
        'assumptions' => [],
        'review_flags' => [],
        'confidence' => [
            'overall' => 95,
            'supplier' => 95,
            'amounts' => 91,
            'dates' => 88,
            'category' => 90,
        ],
    ]);

    $owner = expenseOwner([
        'assistant' => true,
    ]);

    seedExpense($owner, [
        'title' => 'Acme SaaS - April invoice',
        'category_key' => 'software',
        'supplier_name' => 'Acme SaaS',
        'reference_number' => 'INV-204',
        'subtotal' => 100,
        'tax_amount' => 15,
        'total' => 115,
        'expense_date' => now()->toDateString(),
        'due_date' => now()->addDays(15)->toDateString(),
        'status' => Expense::STATUS_DRAFT,
    ]);

    $response = $this->actingAs($owner)
        ->post(route('expense.scan-ai'), [
            'document' => UploadedFile::fake()->create('acme-duplicate-invoice.pdf', 240, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.status', Expense::STATUS_REVIEW_REQUIRED)
        ->assertJsonPath('expense.ai_intake.review_required', true)
        ->assertJsonPath('expense.ai_intake.duplicate_detection.has_matches', true);

    $expense = Expense::query()->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_REVIEW_REQUIRED)
        ->and(data_get($expense->meta, 'ai_intake.duplicate_detection.has_matches'))->toBeTrue()
        ->and(data_get($expense->meta, 'ai_intake.duplicate_detection.match_count'))->toBeGreaterThanOrEqual(1);
});

test('owner can move an expense through the operational workflow', function () {
    $owner = expenseOwner();
    $expense = seedExpense($owner, [
        'status' => Expense::STATUS_DRAFT,
        'paid_date' => null,
        'approved_at' => null,
        'approved_by_user_id' => null,
        'paid_by_user_id' => null,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('expense.submit', $expense))
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_SUBMITTED);

    $this->actingAs($owner)
        ->patchJson(route('expense.approve', $expense))
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_APPROVED);

    $this->actingAs($owner)
        ->patchJson(route('expense.mark-due', $expense))
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_DUE);

    $this->actingAs($owner)
        ->patchJson(route('expense.mark-paid', $expense))
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_PAID)
        ->assertJsonPath('expense.paid_by_user_id', $owner->id);

    $expense->refresh();

    expect($expense->status)->toBe(Expense::STATUS_PAID)
        ->and($expense->approved_by_user_id)->toBe($owner->id)
        ->and($expense->paid_by_user_id)->toBe($owner->id)
        ->and($expense->paid_date)->not->toBeNull()
        ->and(collect($expense->meta['workflow_history'] ?? [])->pluck('action')->all())
        ->toBe(['submit', 'approve', 'mark_due', 'mark_paid']);
});

test('approval requires a category on the expense record', function () {
    $owner = expenseOwner();
    $expense = seedExpense($owner, [
        'status' => Expense::STATUS_SUBMITTED,
        'category_key' => null,
        'approved_at' => null,
        'approved_by_user_id' => null,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('expense.approve', $expense))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_key']);
});

test('workflow actions persist comments and explicit paid date', function () {
    $owner = expenseOwner();
    $expense = seedExpense($owner, [
        'status' => Expense::STATUS_SUBMITTED,
        'paid_date' => null,
        'approved_at' => null,
        'approved_by_user_id' => null,
        'paid_by_user_id' => null,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('expense.approve', $expense), [
            'comment' => 'Budget checked before approval.',
        ])
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_APPROVED);

    $this->actingAs($owner)
        ->patchJson(route('expense.mark-paid', $expense), [
            'comment' => 'Paid by bank transfer.',
            'paid_date' => '2026-04-10',
        ])
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_PAID);

    $expense->refresh();
    $history = collect($expense->meta['workflow_history'] ?? []);

    expect($expense->paid_date?->toDateString())->toBe('2026-04-10')
        ->and($history->firstWhere('action', 'approve')['comment'] ?? null)->toBe('Budget checked before approval.')
        ->and($history->firstWhere('action', 'mark_paid')['comment'] ?? null)->toBe('Paid by bank transfer.');
});

test('reimbursable expenses can be marked as reimbursed with team member trace', function () {
    $owner = expenseOwner([
        'team_members' => true,
    ]);
    $member = expenseTeamMember($owner);
    $expense = seedExpense($owner, [
        'title' => 'Field mileage reimbursement',
        'category_key' => 'reimbursement',
        'status' => Expense::STATUS_DUE,
        'reimbursable' => true,
        'team_member_id' => $member->id,
        'reimbursement_status' => Expense::REIMBURSEMENT_STATUS_PENDING,
        'paid_date' => null,
        'paid_by_user_id' => null,
    ]);

    $this->actingAs($owner)
        ->patchJson(route('expense.mark-reimbursed', $expense), [
            'comment' => 'Mileage reimbursed after validation.',
            'paid_date' => '2026-04-11',
            'reimbursement_reference' => 'ETR-445',
        ])
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_PAID)
        ->assertJsonPath('expense.reimbursement_status', Expense::REIMBURSEMENT_STATUS_REIMBURSED)
        ->assertJsonPath('expense.reimbursed_by_user_id', $owner->id)
        ->assertJsonPath('expense.team_member.id', $member->id);

    $expense->refresh();
    $history = collect($expense->meta['workflow_history'] ?? []);

    expect($expense->paid_date?->toDateString())->toBe('2026-04-11')
        ->and($expense->reimbursement_status)->toBe(Expense::REIMBURSEMENT_STATUS_REIMBURSED)
        ->and($expense->reimbursed_by_user_id)->toBe($owner->id)
        ->and($expense->reimbursement_reference)->toBe('ETR-445')
        ->and($history->firstWhere('action', 'mark_reimbursed')['comment'] ?? null)->toBe('Mileage reimbursed after validation.');
});

test('recurring expense command generates due expenses once per cycle', function () {
    $owner = expenseOwner();
    $this->travelTo(Carbon::parse('2026-04-14 09:00:00'));

    $template = seedExpense($owner, [
        'title' => 'Studio rent',
        'category_key' => 'rent',
        'status' => Expense::STATUS_PAID,
        'expense_date' => '2026-03-14',
        'due_date' => '2026-03-20',
        'paid_date' => '2026-03-20',
        'is_recurring' => true,
        'recurrence_frequency' => Expense::RECURRENCE_FREQUENCY_MONTHLY,
        'recurrence_interval' => 1,
        'recurrence_next_date' => '2026-04-14',
    ]);

    $this->artisan('expenses:generate-recurring', ['--account' => $owner->id])
        ->assertExitCode(0);

    $generated = Expense::query()
        ->where('recurrence_source_expense_id', $template->id)
        ->orderBy('id')
        ->get();

    expect($generated)->toHaveCount(1)
        ->and($generated->first()?->status)->toBe(Expense::STATUS_DUE)
        ->and($generated->first()?->expense_date?->toDateString())->toBe('2026-04-14')
        ->and($generated->first()?->due_date?->toDateString())->toBe('2026-04-20');

    $template->refresh();
    expect($template->recurrence_next_date?->toDateString())->toBe('2026-05-14');

    $this->artisan('expenses:generate-recurring', ['--account' => $owner->id])
        ->assertExitCode(0);

    expect(Expense::query()->where('recurrence_source_expense_id', $template->id)->count())->toBe(1);

    $this->travelBack();
});

test('quick filters can narrow the expense list by workflow state and reimbursement', function () {
    $owner = expenseOwner();

    seedExpense($owner, [
        'title' => 'Draft fuel',
        'status' => Expense::STATUS_DRAFT,
        'paid_date' => null,
    ]);
    seedExpense($owner, [
        'title' => 'Submitted software',
        'status' => Expense::STATUS_SUBMITTED,
        'category_key' => 'software',
        'paid_date' => null,
    ]);
    seedExpense($owner, [
        'title' => 'Paid rent',
        'status' => Expense::STATUS_PAID,
        'category_key' => 'rent',
        'paid_date' => now()->toDateString(),
    ]);
    seedExpense($owner, [
        'title' => 'Mileage refund',
        'status' => Expense::STATUS_DUE,
        'category_key' => 'reimbursement',
        'reimbursable' => true,
        'paid_date' => null,
    ]);
    seedExpense($owner, [
        'title' => 'Monthly rent template',
        'status' => Expense::STATUS_PAID,
        'category_key' => 'rent',
        'is_recurring' => true,
        'recurrence_frequency' => Expense::RECURRENCE_FREQUENCY_MONTHLY,
        'recurrence_interval' => 1,
        'recurrence_next_date' => now()->addMonth()->toDateString(),
        'paid_date' => now()->toDateString(),
    ]);

    $this->actingAs($owner)
        ->getJson(route('expense.index', ['quick_filter' => 'submitted']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('filters.quick_filter', 'submitted')
        ->assertJsonPath('expenses.data.0.title', 'Submitted software');

    $this->actingAs($owner)
        ->getJson(route('expense.index', ['quick_filter' => 'reimbursable']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('expenses.data.0.title', 'Mileage refund');

    $this->actingAs($owner)
        ->getJson(route('expense.index', ['quick_filter' => 'reimbursement_pending']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('expenses.data.0.title', 'Mileage refund');

    $this->actingAs($owner)
        ->getJson(route('expense.index', ['quick_filter' => 'recurring']))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('expenses.data.0.title', 'Monthly rent template');
});

test('owner can create an expense linked to operational records', function () {
    $owner = expenseOwner([
        'jobs' => true,
        'sales' => true,
        'invoices' => true,
        'campaigns' => true,
    ]);
    $customer = expenseCustomerRecord($owner);
    $work = expenseWorkRecord($owner, $customer);
    $sale = expenseSaleRecord($owner, $customer);
    $invoice = expenseInvoiceRecord($owner, $customer, $work);
    $campaign = expenseCampaignRecord($owner);

    $response = $this->actingAs($owner)
        ->postJson(route('expense.store'), [
            'title' => 'Campaign print spend',
            'category_key' => 'marketing',
            'supplier_name' => 'City Print',
            'reference_number' => 'MKT-441',
            'subtotal' => 120,
            'tax_amount' => 18,
            'total' => 138,
            'expense_date' => '2026-04-14',
            'status' => Expense::STATUS_DRAFT,
            'customer_id' => $customer->id,
            'work_id' => $work->id,
            'sale_id' => $sale->id,
            'invoice_id' => $invoice->id,
            'campaign_id' => $campaign->id,
        ]);

    $response->assertCreated()
        ->assertJsonPath('expense.customer_id', $customer->id)
        ->assertJsonPath('expense.work_id', $work->id)
        ->assertJsonPath('expense.sale_id', $sale->id)
        ->assertJsonPath('expense.invoice_id', $invoice->id)
        ->assertJsonPath('expense.campaign_id', $campaign->id);

    $expense = Expense::query()->latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->customer_id)->toBe($customer->id)
        ->and($expense->work_id)->toBe($work->id)
        ->and($expense->sale_id)->toBe($sale->id)
        ->and($expense->invoice_id)->toBe($invoice->id)
        ->and($expense->campaign_id)->toBe($campaign->id);

    $this->actingAs($owner)
        ->getJson(route('expense.index'))
        ->assertOk()
        ->assertJsonPath('linkOptions.customers.0.id', $customer->id)
        ->assertJsonPath('linkOptions.works.0.id', $work->id)
        ->assertJsonPath('linkOptions.sales.0.id', $sale->id)
        ->assertJsonPath('linkOptions.invoices.0.id', $invoice->id)
        ->assertJsonPath('linkOptions.campaigns.0.id', $campaign->id);
});

test('expense index exposes linked reporting stats and filters by linked context', function () {
    $owner = expenseOwner([
        'campaigns' => true,
    ]);
    $customer = expenseCustomerRecord($owner, [
        'company_name' => 'Northwind Studio',
        'email' => 'northwind-'.fake()->unique()->safeEmail(),
    ]);
    $otherCustomer = expenseCustomerRecord($owner, [
        'company_name' => 'Blue Harbor',
        'email' => 'blueharbor-'.fake()->unique()->safeEmail(),
    ]);
    $campaign = expenseCampaignRecord($owner, [
        'name' => 'Meta Lead Burst',
    ]);

    seedExpense($owner, [
        'title' => 'Meta lead ads',
        'category_key' => 'marketing',
        'supplier_name' => 'Meta Ads',
        'total' => 125,
        'tax_amount' => 0,
        'customer_id' => $customer->id,
        'campaign_id' => $campaign->id,
    ]);
    seedExpense($owner, [
        'title' => 'CRM renewal',
        'category_key' => 'software',
        'supplier_name' => 'Acme SaaS',
        'subtotal' => 80,
        'tax_amount' => 0,
        'total' => 80,
        'customer_id' => $otherCustomer->id,
    ]);
    seedExpense($owner, [
        'title' => 'Fuel refill',
        'category_key' => 'fuel',
        'supplier_name' => 'Station Nord',
        'subtotal' => 45,
        'tax_amount' => 0,
        'total' => 45,
    ]);

    $this->actingAs($owner)
        ->getJson(route('expense.index'))
        ->assertOk()
        ->assertJsonPath('stats.linked_total', 205)
        ->assertJsonPath('stats.top_categories.0.key', 'marketing')
        ->assertJsonPath('stats.top_suppliers.0.name', 'Meta Ads');

    $this->actingAs($owner)
        ->getJson(route('expense.index', ['customer_id' => $customer->id]))
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('filters.customer_id', (string) $customer->id)
        ->assertJsonPath('expenses.data.0.title', 'Meta lead ads')
        ->assertJsonPath('expenses.data.0.customer.id', $customer->id);
});

test('filtered expenses can be exported to csv with linked context columns', function () {
    $owner = expenseOwner([
        'jobs' => true,
        'sales' => true,
        'invoices' => true,
        'campaigns' => true,
    ]);
    $customer = expenseCustomerRecord($owner, [
        'company_name' => 'Northwind Studio',
        'email' => 'northwind-export-'.fake()->unique()->safeEmail(),
    ]);
    $otherCustomer = expenseCustomerRecord($owner, [
        'company_name' => 'Blue Harbor',
        'email' => 'blueharbor-export-'.fake()->unique()->safeEmail(),
    ]);
    $work = expenseWorkRecord($owner, $customer, [
        'job_title' => 'Roof inspection',
    ]);
    $sale = expenseSaleRecord($owner, $customer);
    $invoice = expenseInvoiceRecord($owner, $customer, $work);
    $campaign = expenseCampaignRecord($owner, [
        'name' => 'Spring Restart',
    ]);

    seedExpense($owner, [
        'title' => 'Roof campaign spend',
        'category_key' => 'marketing',
        'supplier_name' => 'Meta Ads',
        'reference_number' => 'CSV-001',
        'subtotal' => 125,
        'tax_amount' => 0,
        'total' => 125,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'sale_id' => $sale->id,
        'invoice_id' => $invoice->id,
        'campaign_id' => $campaign->id,
    ]);
    seedExpense($owner, [
        'title' => 'Blue Harbor fuel',
        'supplier_name' => 'Station East',
        'customer_id' => $otherCustomer->id,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('expense.export', ['customer_id' => $customer->id]));

    $csv = $response->streamedContent();

    $response->assertOk();

    expect((string) $response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toContain('customer,work,sale,invoice,campaign')
        ->and($csv)->toContain('Northwind Studio')
        ->and($csv)->toContain((string) $work->number)
        ->and($csv)->toContain((string) $sale->number)
        ->and($csv)->toContain((string) $invoice->number)
        ->and($csv)->toContain('Spring Restart')
        ->and($csv)->not->toContain('Blue Harbor fuel');
});

test('api exposes reject, reimbursed, and export expense endpoints', function () {
    $owner = expenseOwner([
        'team_members' => true,
    ]);
    $member = expenseTeamMember($owner);

    Sanctum::actingAs($owner);

    $submittedExpense = seedExpense($owner, [
        'title' => 'Submitted API expense',
        'reference_number' => 'API-REJECT-1',
        'status' => Expense::STATUS_SUBMITTED,
        'due_date' => null,
    ]);

    $this->patchJson("/api/v1/expenses/{$submittedExpense->id}/reject")
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_REJECTED);

    $reimbursableExpense = seedExpense($owner, [
        'title' => 'Reimbursable API expense',
        'reference_number' => 'API-REIMBURSE-1',
        'status' => Expense::STATUS_DUE,
        'reimbursable' => true,
        'team_member_id' => $member->id,
        'reimbursement_status' => Expense::REIMBURSEMENT_STATUS_PENDING,
        'paid_date' => null,
        'paid_by_user_id' => null,
    ]);

    $this->patchJson("/api/v1/expenses/{$reimbursableExpense->id}/mark-reimbursed", [
        'comment' => 'Reimbursed through API contract.',
        'paid_date' => '2026-04-12',
        'reimbursement_reference' => 'API-RMB-1',
    ])
        ->assertOk()
        ->assertJsonPath('expense.status', Expense::STATUS_PAID)
        ->assertJsonPath('expense.reimbursement_status', Expense::REIMBURSEMENT_STATUS_REIMBURSED)
        ->assertJsonPath('expense.team_member.id', $member->id);

    $response = $this->get('/api/v1/expenses/export');
    $csv = $response->streamedContent();

    $response->assertOk();

    expect((string) $response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toContain('title,status,reimbursement_status')
        ->and($csv)->toContain('Submitted API expense')
        ->and($csv)->toContain('Reimbursable API expense');
});
