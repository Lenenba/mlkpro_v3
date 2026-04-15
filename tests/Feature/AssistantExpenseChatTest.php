<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.expense_scan_model', 'gpt-4.1-mini');
    config()->set('billing.provider_effective', 'paddle');
});

function fakeAssistantExpenseOpenAi(array $payload, string $model = 'gpt-4.1-mini'): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-assistant-expense',
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
                'prompt_tokens' => 980,
                'completion_tokens' => 145,
                'total_tokens' => 1125,
            ],
        ], 200),
    ]);
}

function assistantExpenseOwner(array $featureOverrides = []): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_features' => array_replace([
            'assistant' => true,
            'expenses' => true,
            'plan_scans' => false,
        ], $featureOverrides),
        'locale' => 'fr',
    ]);
}

test('assistant can create an expense draft from an attached supplier invoice', function () {
    Storage::fake('public');
    Storage::fake('local');

    fakeAssistantExpenseOpenAi([
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
            'overall' => 94,
            'supplier' => 95,
            'amounts' => 91,
            'dates' => 88,
            'category' => 90,
        ],
    ]);

    $owner = assistantExpenseOwner([
        'plan_scans' => true,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'message' => 'Analyse cette facture fournisseur et cree une depense.',
            'attachment' => UploadedFile::fake()->create('supplier-invoice.pdf', 240, 'application/pdf'),
        ]);

    $expense = Expense::query()->latest('id')->first();
    $attachment = ExpenseAttachment::query()->where('expense_id', $expense?->id)->latest('id')->first();

    $response->assertOk()
        ->assertJsonPath('status', 'expense_created')
        ->assertJsonPath('action.type', 'expense_created')
        ->assertJsonPath('action.expense_id', $expense?->id)
        ->assertJsonPath('expense.id', $expense?->id)
        ->assertJsonPath('expense.supplier_name', 'Acme SaaS')
        ->assertJsonPath('expense.ai_review_required', false)
        ->assertJsonPath('context.current_expense.id', $expense?->id);

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_DRAFT)
        ->and($expense->supplier_name)->toBe('Acme SaaS')
        ->and($expense->category_key)->toBe('software')
        ->and(data_get($expense->meta, 'ai_intake.source'))->toBe('assistant_chat')
        ->and(data_get($expense->meta, 'ai_intake.assistant_message'))->toBe('Analyse cette facture fournisseur et cree une depense.')
        ->and($attachment)->not->toBeNull();
});

test('assistant can default an attachment to expense intake when plan scans are unavailable', function () {
    Storage::fake('public');
    Storage::fake('local');

    fakeAssistantExpenseOpenAi([
        'document_type' => 'receipt',
        'title' => 'Fuel receipt',
        'supplier_name' => 'Station Nord',
        'reference_number' => 'RCPT-908',
        'expense_date' => now()->toDateString(),
        'due_date' => null,
        'currency_code' => 'CAD',
        'subtotal' => 52,
        'tax_amount' => 8,
        'total' => 60,
        'suggested_category' => 'fuel',
        'description' => 'Vehicle fuel receipt',
        'assumptions' => [],
        'review_flags' => [],
        'confidence' => [
            'overall' => 90,
            'supplier' => 93,
            'amounts' => 89,
            'dates' => 86,
            'category' => 90,
        ],
    ]);

    $owner = assistantExpenseOwner();

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'attachment' => UploadedFile::fake()->create('fuel-receipt.pdf', 120, 'application/pdf'),
        ]);

    $expense = Expense::query()->latest('id')->first();

    $response->assertOk()
        ->assertJsonPath('status', 'expense_created')
        ->assertJsonPath('action.expense_id', $expense?->id)
        ->assertJsonPath('expense.supplier_name', 'Station Nord');

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_DRAFT)
        ->and($expense->category_key)->toBe('fuel');
});

test('assistant asks for confirmation before creating an ambiguous expense draft', function () {
    Storage::fake('public');
    Storage::fake('local');

    fakeAssistantExpenseOpenAi([
        'document_type' => 'invoice',
        'title' => 'Office supply invoice',
        'supplier_name' => 'Bureau Central',
        'reference_number' => 'INV-771',
        'expense_date' => now()->toDateString(),
        'due_date' => null,
        'currency_code' => 'CAD',
        'subtotal' => 80,
        'tax_amount' => 12,
        'total' => 92,
        'suggested_category' => 'other',
        'description' => 'Office supplies',
        'assumptions' => [
            'Supplier name partially obscured.',
        ],
        'review_flags' => [
            'Confirm the category before approval.',
        ],
        'confidence' => [
            'overall' => 72,
            'supplier' => 74,
            'amounts' => 88,
            'dates' => 83,
            'category' => 55,
        ],
    ]);

    $owner = assistantExpenseOwner();

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'message' => 'Lis cette facture et cree la depense si c est exploitable.',
            'attachment' => UploadedFile::fake()->create('ambiguous-office-invoice.pdf', 180, 'application/pdf'),
        ]);

    $pendingAction = data_get($response->json(), 'context.pending_action');

    $response->assertOk()
        ->assertJsonPath('status', 'needs_confirmation')
        ->assertJsonPath('context.pending_action.type', 'create_expense_from_attachment');

    expect(Expense::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('assistant-expense-staging'))->toHaveCount(1);

    $confirmResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('assistant.message'), [
            'message' => 'oui',
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ]);

    $expense = Expense::query()->latest('id')->first();

    $confirmResponse->assertOk()
        ->assertJsonPath('status', 'expense_created')
        ->assertJsonPath('expense.id', $expense?->id);

    expect($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_REVIEW_REQUIRED)
        ->and(data_get($expense->meta, 'ai_intake.review_required'))->toBeTrue()
        ->and(Storage::disk('local')->allFiles('assistant-expense-staging'))->toHaveCount(0);
});

test('assistant asks for confirmation when a similar invoice already exists', function () {
    Storage::fake('public');
    Storage::fake('local');

    fakeAssistantExpenseOpenAi([
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
            'amounts' => 93,
            'dates' => 91,
            'category' => 92,
        ],
    ]);

    $owner = assistantExpenseOwner();

    Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Acme SaaS - April invoice',
        'category_key' => 'software',
        'supplier_name' => 'Acme SaaS',
        'reference_number' => 'INV-204',
        'currency_code' => 'CAD',
        'subtotal' => 100,
        'tax_amount' => 15,
        'total' => 115,
        'expense_date' => now()->toDateString(),
        'due_date' => now()->addDays(15)->toDateString(),
        'status' => Expense::STATUS_DRAFT,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'message' => 'Enregistre cette facture fournisseur.',
            'attachment' => UploadedFile::fake()->create('acme-duplicate.pdf', 200, 'application/pdf'),
        ]);

    $pendingAction = data_get($response->json(), 'context.pending_action');

    $response->assertOk()
        ->assertJsonPath('status', 'needs_confirmation')
        ->assertJsonPath('context.pending_action.type', 'create_expense_from_attachment')
        ->assertJsonPath('expense_choice.mode', 'duplicate_resolution')
        ->assertJsonPath('expense_choice.choices.0.type', 'open_existing')
        ->assertJsonPath('expense_choice.choices.1.type', 'create_anyway');

    expect(Expense::query()->count())->toBe(1);

    $confirmResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('assistant.message'), [
            'message' => 'oui',
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ]);

    $expense = Expense::query()->latest('id')->first();

    $confirmResponse->assertOk()
        ->assertJsonPath('status', 'expense_created')
        ->assertJsonPath('expense.id', $expense?->id)
        ->assertJsonPath('expense.ai_review_required', true);

    expect(Expense::query()->count())->toBe(2)
        ->and($expense)->not->toBeNull()
        ->and($expense->status)->toBe(Expense::STATUS_REVIEW_REQUIRED)
        ->and(data_get($expense->meta, 'ai_intake.duplicate_detection.has_matches'))->toBeTrue()
        ->and(data_get($expense->meta, 'ai_intake.duplicate_detection.match_count'))->toBeGreaterThanOrEqual(1);
});

test('assistant can open the existing expense instead of creating a duplicate draft', function () {
    Storage::fake('public');
    Storage::fake('local');

    fakeAssistantExpenseOpenAi([
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
            'amounts' => 93,
            'dates' => 91,
            'category' => 92,
        ],
    ]);

    $owner = assistantExpenseOwner();

    $existingExpense = Expense::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'title' => 'Acme SaaS - April invoice',
        'category_key' => 'software',
        'supplier_name' => 'Acme SaaS',
        'reference_number' => 'INV-204',
        'currency_code' => 'CAD',
        'subtotal' => 100,
        'tax_amount' => 15,
        'total' => 115,
        'expense_date' => now()->toDateString(),
        'due_date' => now()->addDays(15)->toDateString(),
        'status' => Expense::STATUS_DRAFT,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'message' => 'Analyse cette facture.',
            'attachment' => UploadedFile::fake()->create('existing-acme-invoice.pdf', 200, 'application/pdf'),
        ]);

    $pendingAction = data_get($response->json(), 'context.pending_action');

    $openResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('assistant.message'), [
            'message' => 'ouvrir la depense existante',
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ]);

    $openResponse->assertOk()
        ->assertJsonPath('status', 'expense_existing_opened')
        ->assertJsonPath('action.type', 'open_expense')
        ->assertJsonPath('action.expense_id', $existingExpense->id)
        ->assertJsonPath('expense.id', $existingExpense->id)
        ->assertJsonPath('context.pending_action', null);

    expect(Expense::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('assistant-expense-staging'))->toHaveCount(0);
});
