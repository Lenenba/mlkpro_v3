<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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
