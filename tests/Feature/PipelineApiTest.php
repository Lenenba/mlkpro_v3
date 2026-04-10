<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the canonical owner pipeline contract for every supported source type', function (string $sourceType) {
    $owner = User::factory()->create([
        'role_id' => pipelineRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $graph = createCompletePipelineGraph($owner);

    Sanctum::actingAs($owner);

    $sourceEntity = match ($sourceType) {
        'request' => $graph['lead'],
        'quote' => $graph['quote'],
        'job' => $graph['work'],
        'task' => $graph['billedTask'],
        'invoice' => $graph['invoice'],
    };

    $response = $this->getJson("/api/v1/pipeline?entityType={$sourceType}&entityId={$sourceEntity->id}")
        ->assertOk()
        ->assertJsonPath('source.type', $sourceType)
        ->assertJsonPath('source.id', (string) $sourceEntity->id)
        ->assertJsonPath('request.id', $graph['lead']->id)
        ->assertJsonPath('request.title', 'Pipeline Request')
        ->assertJsonPath('request.customer.name', 'Pipeline Client')
        ->assertJsonPath('quote.id', $graph['quote']->id)
        ->assertJsonPath('quote.status', 'accepted')
        ->assertJsonPath('job.id', $graph['work']->id)
        ->assertJsonPath('job.status', 'in_progress')
        ->assertJsonPath('invoice.id', $graph['invoice']->id)
        ->assertJsonPath('invoice.status', 'partial')
        ->assertJsonPath('billing.quote_total', 1200)
        ->assertJsonPath('billing.invoice_total', 800)
        ->assertJsonPath('billing.remaining_to_bill', 400)
        ->assertJsonPath('billing.amount_paid', 300)
        ->assertJsonPath('billing.balance_due', 500)
        ->assertJsonPath('derived.completeness', 100)
        ->assertJsonPath('derived.globalStatus', 'partial');

    expect($response->json('derived.alerts'))->toContain('Tasks pending.');

    $tasks = collect($response->json('tasks'))->keyBy('id');

    expect($tasks)->toHaveCount(2);

    $billedTask = $tasks->get($graph['billedTask']->id);
    $pendingTask = $tasks->get($graph['pendingTask']->id);

    expect($billedTask)->not->toBeNull()
        ->and($billedTask['title'])->toBe('Completed billed task')
        ->and($billedTask['assignee'])->toBe('Pipeline Worker')
        ->and($billedTask['billable'])->toBeTrue()
        ->and($billedTask['billing_status'])->toBe('partial')
        ->and($billedTask['invoice_id'])->toBe($graph['invoice']->id)
        ->and($pendingTask)->not->toBeNull()
        ->and($pendingTask['title'])->toBe('Pending unbilled task')
        ->and($pendingTask['billing_status'])->toBe('unbilled');
})->with(['request', 'quote', 'job', 'task', 'invoice']);

test('pipeline api returns an incomplete request-only payload when downstream entities do not exist', function () {
    $owner = User::factory()->create([
        'role_id' => pipelineRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Lead Only Client',
        'email' => 'lead-only@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Lead only request',
        'service_type' => 'Consulting',
    ]);

    Sanctum::actingAs($owner);

    $response = $this->getJson("/api/v1/pipeline?entityType=request&entityId={$lead->id}")
        ->assertOk()
        ->assertJsonPath('source.type', 'request')
        ->assertJsonPath('source.id', (string) $lead->id)
        ->assertJsonPath('request.id', $lead->id)
        ->assertJsonPath('quote', null)
        ->assertJsonPath('job', null)
        ->assertJsonPath('tasks', [])
        ->assertJsonPath('invoice', null)
        ->assertJsonPath('billing.quote_total', null)
        ->assertJsonPath('billing.invoice_total', null)
        ->assertJsonPath('derived.completeness', 20)
        ->assertJsonPath('derived.globalStatus', LeadRequest::STATUS_QUALIFIED);

    expect($response->json('derived.alerts'))->toBe(['Quote not created yet.']);
});

test('pipeline api forbids team members even inside the same workspace', function () {
    $owner = User::factory()->create([
        'role_id' => pipelineRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $employee = User::factory()->create([
        'role_id' => pipelineRoleId('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['jobs.view', 'tasks.view'],
        'is_active' => true,
    ]);

    $graph = createCompletePipelineGraph($owner);

    Sanctum::actingAs($employee);

    $this->getJson("/api/v1/pipeline?entityType=request&entityId={$graph['lead']->id}")
        ->assertForbidden();
});

function pipelineRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function createCompletePipelineGraph(User $owner): array
{
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Pipeline Client',
        'email' => 'pipeline-client@example.test',
        'phone' => '+15555550100',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Pipeline Request',
        'service_type' => 'Landscaping',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'property_id' => null,
        'request_id' => $lead->id,
        'job_title' => 'Pipeline Quote',
        'status' => 'accepted',
        'subtotal' => 1000,
        'total' => 1200,
        'initial_deposit' => 200,
        'is_fixed' => true,
        'notes' => 'Accepted quote for pipeline coverage.',
        'accepted_at' => now(),
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Pipeline Job',
        'instructions' => 'Execute the approved pipeline job.',
        'status' => 'in_progress',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_all_day' => false,
        'later' => false,
        'ends' => 'Never',
        'frequencyNumber' => 1,
        'frequency' => 'Weekly',
        'totalVisits' => 1,
        'repeatsOn' => [],
        'subtotal' => 1000,
        'total' => 1200,
    ]);

    $quote->update(['work_id' => $work->id]);

    $assigneeUser = User::factory()->create([
        'role_id' => pipelineRoleId('employee', 'Employee role'),
        'name' => 'Pipeline Worker',
        'email' => 'pipeline-worker@example.test',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $assignee = TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $assigneeUser->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
        'is_active' => true,
    ]);

    $billedTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'assigned_team_member_id' => $assignee->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'request_id' => $lead->id,
        'title' => 'Completed billed task',
        'description' => 'This task should appear as billed in the pipeline.',
        'status' => 'done',
        'billable' => true,
        'due_date' => now()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'completed_at' => now(),
    ]);

    $pendingTask = Task::query()->create([
        'account_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'request_id' => $lead->id,
        'title' => 'Pending unbilled task',
        'description' => 'This task keeps the pipeline in a partially pending state.',
        'status' => 'todo',
        'billable' => true,
        'due_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $invoice = Invoice::query()->create([
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'work_id' => $work->id,
        'status' => 'partial',
        'total' => 800,
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'task_id' => $billedTask->id,
        'work_id' => $work->id,
        'assigned_team_member_id' => $assignee->id,
        'title' => 'Completed billed task',
        'description' => 'Billed pipeline work item.',
        'scheduled_date' => now()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'assignee_name' => 'Pipeline Worker',
        'task_status' => 'done',
        'quantity' => 1,
        'unit_price' => 300,
        'total' => 300,
    ]);

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'amount' => 300,
        'method' => 'card',
        'status' => Payment::STATUS_COMPLETED,
        'paid_at' => now(),
    ]);

    return compact('customer', 'lead', 'quote', 'work', 'invoice', 'assignee', 'billedTask', 'pendingTask');
}
