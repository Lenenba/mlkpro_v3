<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\AccountingAccount;
use App\Models\AccountingEntry;
use App\Models\AccountingEntryBatch;
use App\Models\AccountingExport;
use App\Models\AccountingMapping;
use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function accountingRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function accountingOwner(array $overrides = []): User
{
    $defaults = [
        'name' => 'Accounting Owner',
        'email' => 'accounting-owner@example.com',
        'password' => 'password',
        'role_id' => accountingRoleId('owner', 'Account owner role'),
        'company_type' => 'services',
        'company_sector' => 'field_services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'expenses' => true,
            'accounting' => true,
            'invoices' => true,
            'sales' => true,
        ],
    ];

    return User::query()->create(array_replace_recursive($defaults, $overrides));
}

function accountingEmployee(User $owner, array $permissions = []): User
{
    $employee = User::query()->create([
        'name' => 'Accounting Employee',
        'email' => 'accounting-employee-'.strtolower(\Illuminate\Support\Str::random(10)).'@example.com',
        'password' => 'password',
        'role_id' => accountingRoleId('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
        'company_features' => $owner->company_features,
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'admin',
        'permissions' => $permissions,
        'is_active' => true,
    ]);

    return $employee->fresh();
}

function accountingCustomer(User $owner): Customer
{
    return Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'company_name' => 'Northwind Studio',
        'email' => 'accounting-customer-'.strtolower(\Illuminate\Support\Str::random(10)).'@example.com',
        'phone' => '+1 514 555 0101',
        'billing_same_as_physical' => false,
    ]);
}

function accountingWork(User $owner, Customer $customer): Work
{
    return Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Window cleaning',
        'instructions' => 'Full building service.',
        'status' => Work::STATUS_TO_SCHEDULE,
    ]);
}

test('owner can open the accounting phase zero workspace', function () {
    $owner = accountingOwner();

    $this->actingAs($owner)
        ->get(route('accounting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Index')
            ->where('status.phase', 'phase_5')
            ->where('status.state', 'mobile_supervision_ready')
            ->where('snapshot.currency_code', $owner->businessCurrencyCode())
            ->where('source_counts.expenses', 0)
            ->where('source_counts.invoices', 0)
            ->where('source_counts.payments', 0)
            ->where('source_counts.sales', 0)
            ->where('abilities.can_manage', true)
            ->where('mobile_summary.cash_in', 0)
            ->where('mobile_summary.cash_out', 0)
            ->has('system_accounts')
            ->has('mapping_conventions')
            ->has('journal.data')
            ->has('journal_summary')
            ->has('review_workspace')
            ->has('mobile_alerts')
            ->has('next_steps')
        );
});

test('team member with accounting permission can access the accounting workspace', function () {
    $owner = accountingOwner([
        'company_features' => [
            'team_members' => true,
            'expenses' => true,
            'accounting' => true,
            'invoices' => true,
        ],
    ]);
    $employee = accountingEmployee($owner, ['accounting.view']);

    $this->actingAs($employee)
        ->get(route('accounting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Index')
            ->where('status.phase', 'phase_5')
            ->where('abilities.can_manage', false)
        );
});

test('team member without accounting permission cannot access the accounting workspace api', function () {
    $owner = accountingOwner([
        'company_features' => [
            'team_members' => true,
            'expenses' => true,
            'accounting' => true,
        ],
    ]);
    $employee = accountingEmployee($owner, ['expenses.view']);

    Sanctum::actingAs($employee);

    $this->getJson('/api/v1/accounting')
        ->assertForbidden();
});

test('api accounting endpoint stays unavailable when expenses dependency is missing', function () {
    $owner = accountingOwner([
        'company_features' => [
            'expenses' => false,
            'accounting' => true,
        ],
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/accounting')
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');
});

test('accounting phase one bootstraps accounts mappings and journal entries from trusted finance sources', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Cleaning package',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 115,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'created_by_user_id' => $owner->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 200,
        'tax_total' => 30,
        'total' => 230,
        'currency_code' => 'CAD',
        'paid_at' => now(),
    ]);

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Fuel refill',
        'category_key' => 'fuel',
        'supplier_name' => 'Station Nord',
        'reference_number' => 'EXP-100',
        'currency_code' => 'CAD',
        'subtotal' => 42,
        'tax_amount' => 3,
        'total' => 45,
        'expense_date' => now()->toDateString(),
        'paid_date' => now()->toDateString(),
        'status' => Expense::STATUS_PAID,
    ]);

    $this->actingAs($owner)
        ->get(route('accounting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounting/Index')
            ->where('status.phase', 'phase_5')
            ->where('status.state', 'mobile_supervision_ready')
            ->where('journal_summary.entry_count', 10)
            ->where('journal_summary.batch_count', 4)
            ->where('source_counts.expenses', 1)
            ->where('source_counts.invoices', 1)
            ->where('source_counts.payments', 1)
            ->where('source_counts.sales', 1)
            ->where('mobile_summary.cash_out', 45)
            ->has('journal.data', 10)
            ->has('review_workspace.batches')
            ->has('system_accounts', 8)
            ->has('mapping_conventions', 6)
        );

    expect(AccountingAccount::query()->where('user_id', $owner->id)->count())->toBe(8);
    expect(AccountingMapping::query()->where('user_id', $owner->id)->count())->toBe(6);
    expect(AccountingEntryBatch::query()->where('user_id', $owner->id)->count())->toBe(4);
    expect(AccountingEntry::query()->where('user_id', $owner->id)->count())->toBe(10);

    $invoiceBatch = AccountingEntryBatch::query()
        ->where('user_id', $owner->id)
        ->where('source_type', 'invoice')
        ->where('source_id', $invoice->id)
        ->first();

    expect($invoiceBatch)->not->toBeNull();
    expect($invoiceBatch->status)->toBe(AccountingEntryBatch::STATUS_GENERATED);
});

test('accounting journal filters by source type in the api response', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 75,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Service line',
        'quantity' => 1,
        'unit_price' => 75,
        'total' => 75,
        'currency_code' => 'CAD',
    ]);

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Office supplies',
        'category_key' => 'office',
        'currency_code' => 'CAD',
        'subtotal' => 20,
        'tax_amount' => 0,
        'total' => 20,
        'expense_date' => now()->toDateString(),
        'paid_date' => now()->toDateString(),
        'status' => Expense::STATUS_PAID,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/accounting?source_type=invoice')
        ->assertOk()
        ->assertJsonPath('status.phase', 'phase_5')
        ->assertJsonCount(2, 'journal.data')
        ->assertJsonPath('journal_summary.batch_count', 1)
        ->assertJsonPath('journal.data.0.batch.source_type', 'invoice');
});

test('reimbursable expenses generate payable and settlement batches', function () {
    $owner = accountingOwner();

    $expense = Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Team purchase',
        'category_key' => 'materials',
        'reference_number' => 'EXP-R-1',
        'currency_code' => 'CAD',
        'subtotal' => 90,
        'tax_amount' => 10,
        'total' => 100,
        'expense_date' => now()->toDateString(),
        'paid_date' => now()->toDateString(),
        'reimbursable' => true,
        'reimbursement_status' => Expense::REIMBURSEMENT_STATUS_REIMBURSED,
        'reimbursed_at' => now(),
        'status' => Expense::STATUS_REIMBURSED,
    ]);

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    expect(AccountingEntryBatch::query()
        ->where('user_id', $owner->id)
        ->where('source_type', 'expense')
        ->where('source_id', $expense->id)
        ->where('source_event_key', 'reimbursable_expense_paid')
        ->exists())->toBeTrue();

    expect(AccountingEntryBatch::query()
        ->where('user_id', $owner->id)
        ->where('source_type', 'expense')
        ->where('source_id', $expense->id)
        ->where('source_event_key', 'reimbursable_expense_reimbursed')
        ->exists())->toBeTrue();
});

test('accounting phase two returns tax summary by selected period', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
        'created_at' => now()->startOfMonth()->addDay(),
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Monthly service',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'created_by_user_id' => $owner->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 200,
        'tax_total' => 30,
        'total' => 230,
        'currency_code' => 'CAD',
        'paid_at' => now()->startOfMonth()->addDays(2),
    ]);

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Fuel refill',
        'category_key' => 'fuel',
        'currency_code' => 'CAD',
        'subtotal' => 50,
        'tax_amount' => 5,
        'total' => 55,
        'expense_date' => now()->startOfMonth()->addDays(3)->toDateString(),
        'paid_date' => now()->startOfMonth()->addDays(3)->toDateString(),
        'status' => Expense::STATUS_PAID,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/accounting?period='.now()->format('Y-m'))
        ->assertOk()
        ->assertJsonPath('status.phase', 'phase_5')
        ->assertJsonPath('tax_summary.taxes_collected', 45)
        ->assertJsonPath('tax_summary.taxes_paid', 5)
        ->assertJsonPath('tax_summary.net_tax_due', 40)
        ->assertJsonPath('tax_summary.period_key', now()->format('Y-m'))
        ->assertJsonCount(3, 'tax_summary.source_breakdown');
});

test('accounting export generates csv, stores audit history, and can be downloaded again', function () {
    Storage::fake('local');

    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Cleaning package',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Office supplies',
        'category_key' => 'office',
        'reference_number' => 'EXP-500',
        'currency_code' => 'CAD',
        'subtotal' => 20,
        'tax_amount' => 3,
        'total' => 23,
        'expense_date' => now()->toDateString(),
        'paid_date' => now()->toDateString(),
        'status' => Expense::STATUS_PAID,
    ]);

    $response = $this->actingAs($owner)
        ->get(route('accounting.export', ['period' => now()->format('Y-m')]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $content = $response->streamedContent();

    expect($content)->toContain('entry_id');
    expect($content)->toContain('source_type');
    expect($content)->toContain('invoice');
    expect($content)->toContain('expense');

    $export = AccountingExport::query()->latest('id')->first();

    expect($export)->not->toBeNull();
    expect($export->format)->toBe(AccountingExport::FORMAT_CSV);
    expect($export->generated_by)->toBe($owner->id);
    expect(Storage::disk('local')->exists($export->path))->toBeTrue();
    expect(ActivityLog::query()
        ->where('subject_type', $export->getMorphClass())
        ->where('subject_id', $export->id)
        ->where('action', 'accounting.export.generated')
        ->exists())->toBeTrue();

    $this->actingAs($owner)
        ->get(route('accounting.exports.download', $export))
        ->assertOk();

    $this->actingAs($owner)
        ->get(route('accounting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status.phase', 'phase_5')
            ->has('export_history', 1)
            ->where('export_history.0.id', $export->id)
            ->where('export_history.0.row_count', (int) data_get($export->meta, 'row_count', 0))
        );
});

test('accounting review workspace surfaces pending batches in the api response', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Monthly service',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/accounting')
        ->assertOk()
        ->assertJsonPath('status.phase', 'phase_5')
        ->assertJsonPath('status.state', 'mobile_supervision_ready')
        ->assertJsonPath('review_workspace.pending_batch_count', 1)
        ->assertJsonPath('review_workspace.entry_status_counts.unreviewed', 3)
        ->assertJsonPath('review_workspace.batches.0.source_type', 'invoice')
        ->assertJsonPath('review_workspace.batches.0.review_status', AccountingEntry::REVIEW_STATUS_UNREVIEWED);
});

test('accounting mobile summary exposes finance supervision metrics and alerts', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Monthly service',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 115,
        'currency_code' => 'CAD',
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Fuel refill',
        'category_key' => 'fuel',
        'currency_code' => 'CAD',
        'subtotal' => 42,
        'tax_amount' => 3,
        'total' => 45,
        'expense_date' => now()->toDateString(),
        'paid_date' => now()->toDateString(),
        'status' => Expense::STATUS_PAID,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/accounting')
        ->assertOk()
        ->assertJsonPath('mobile_summary.cash_in', 115)
        ->assertJsonPath('mobile_summary.cash_out', 45)
        ->assertJsonPath('mobile_summary.pending_batch_count', 3)
        ->assertJsonPath('mobile_summary.unreconciled_entry_count', 7)
        ->assertJsonPath('mobile_alerts.0.key', 'pending_review');
});

test('accounting entries and batches can be reviewed and reconciled with audit trail', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Monthly service',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $batch = AccountingEntryBatch::query()
        ->where('user_id', $owner->id)
        ->where('source_type', 'invoice')
        ->where('source_id', $invoice->id)
        ->first();

    expect($batch)->not->toBeNull();

    $entry = $batch->entries()->orderBy('id')->first();

    $this->actingAs($owner)
        ->patch(route('accounting.entries.review', ['accountingEntry' => $entry]))
        ->assertRedirect();

    $entry->refresh();
    expect($entry->review_status)->toBe(AccountingEntry::REVIEW_STATUS_REVIEWED);
    expect($entry->reconciliation_status)->toBe(AccountingEntry::REVIEW_STATUS_REVIEWED);

    $this->actingAs($owner)
        ->patch(route('accounting.batches.reconcile', ['accountingEntryBatch' => $batch]))
        ->assertRedirect();

    $batch->refresh();
    $batch->load('entries');

    expect(data_get($batch->meta, 'review_status'))->toBe(AccountingEntry::REVIEW_STATUS_RECONCILED);
    expect($batch->entries->every(
        fn (AccountingEntry $batchEntry): bool => $batchEntry->review_status === AccountingEntry::REVIEW_STATUS_RECONCILED
            && $batchEntry->reconciliation_status === AccountingEntry::REVIEW_STATUS_RECONCILED
    ))->toBeTrue();

    expect(ActivityLog::query()
        ->where('subject_type', $entry->getMorphClass())
        ->where('subject_id', $entry->id)
        ->where('action', 'accounting.entry.review_status_changed')
        ->exists())->toBeTrue();

    expect(ActivityLog::query()
        ->where('subject_type', $batch->getMorphClass())
        ->where('subject_id', $batch->id)
        ->where('action', 'accounting.batch.review_status_changed')
        ->exists())->toBeTrue();
});

test('accounting review status survives journal resyncs for unchanged batches', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Monthly service',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $batch = AccountingEntryBatch::query()
        ->where('user_id', $owner->id)
        ->where('source_type', 'invoice')
        ->where('source_id', $invoice->id)
        ->first();

    expect($batch)->not->toBeNull();

    $this->actingAs($owner)
        ->patch(route('accounting.batches.review', ['accountingEntryBatch' => $batch]))
        ->assertRedirect();

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $batch->refresh();
    $batch->load('entries');

    expect(data_get($batch->meta, 'review_status'))->toBe(AccountingEntry::REVIEW_STATUS_REVIEWED);
    expect($batch->entries->every(
        fn (AccountingEntry $entry): bool => $entry->review_status === AccountingEntry::REVIEW_STATUS_REVIEWED
            && $entry->reconciliation_status === AccountingEntry::REVIEW_STATUS_REVIEWED
    ))->toBeTrue();
});

test('team members with view-only accounting access cannot review or reconcile entries', function () {
    $owner = accountingOwner([
        'company_features' => [
            'team_members' => true,
            'expenses' => true,
            'accounting' => true,
            'invoices' => true,
        ],
    ]);
    $employee = accountingEmployee($owner, ['accounting.view']);
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Monthly service',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $entry = AccountingEntry::query()
        ->where('user_id', $owner->id)
        ->whereHas('batch', fn ($query) => $query
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id))
        ->first();

    expect($entry)->not->toBeNull();

    Sanctum::actingAs($employee);

    $this->patchJson('/api/v1/accounting/entries/'.$entry->id.'/review')
        ->assertForbidden();
});

test('accounting periods can move through in review closed and reopened states', function () {
    $owner = accountingOwner();
    $periodKey = now()->format('Y-m');

    $this->actingAs($owner)
        ->patch(route('accounting.periods.in-review', ['periodKey' => $periodKey]))
        ->assertRedirect();

    $period = AccountingPeriod::query()
        ->where('user_id', $owner->id)
        ->where('period_key', $periodKey)
        ->first();

    expect($period)->not->toBeNull();
    expect($period->status)->toBe(AccountingPeriod::STATUS_IN_REVIEW);

    $this->actingAs($owner)
        ->patch(route('accounting.periods.close', ['periodKey' => $periodKey]))
        ->assertRedirect();

    $period->refresh();
    expect($period->status)->toBe(AccountingPeriod::STATUS_CLOSED);
    expect($period->closed_by)->toBe($owner->id);
    expect($period->closed_at)->not->toBeNull();

    $this->actingAs($owner)
        ->patch(route('accounting.periods.reopen', ['periodKey' => $periodKey]))
        ->assertRedirect();

    $period->refresh();
    expect($period->status)->toBe(AccountingPeriod::STATUS_REOPENED);
    expect($period->reopened_by)->toBe($owner->id);
    expect($period->reopened_at)->not->toBeNull();

    expect(ActivityLog::query()
        ->where('subject_type', $period->getMorphClass())
        ->where('subject_id', $period->id)
        ->where('action', 'accounting.period.status_changed')
        ->count())->toBe(3);
});

test('closed accounting periods prevent silent journal drift until reopened', function () {
    $owner = accountingOwner();
    $customer = accountingCustomer($owner);
    $work = accountingWork($owner, $customer);
    $periodKey = now()->format('Y-m');

    $invoice = Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'created_by_user_id' => $owner->id,
        'status' => 'sent',
        'approval_status' => 'approved',
        'total' => 115,
        'currency_code' => 'CAD',
        'created_at' => now()->startOfMonth()->addDay(),
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'title' => 'Initial line',
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $batch = AccountingEntryBatch::query()
        ->where('user_id', $owner->id)
        ->where('source_type', 'invoice')
        ->where('source_id', $invoice->id)
        ->first();

    expect($batch)->not->toBeNull();
    expect((float) $batch->entries()->where('direction', 'debit')->value('amount'))->toBe(115.0);

    $this->actingAs($owner)
        ->patch(route('accounting.periods.close', ['periodKey' => $periodKey]))
        ->assertRedirect();

    $invoice->update(['total' => 140]);
    $invoice->items()->update(['total' => 120, 'unit_price' => 120]);

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $batch->refresh();
    expect((float) $batch->entries()->where('direction', 'debit')->value('amount'))->toBe(115.0);

    $this->actingAs($owner)
        ->patch(route('accounting.periods.reopen', ['periodKey' => $periodKey]))
        ->assertRedirect();

    $this->actingAs($owner)->get(route('accounting.index'))->assertOk();

    $batch->refresh();
    expect((float) $batch->entries()->where('direction', 'debit')->value('amount'))->toBe(140.0);
});
