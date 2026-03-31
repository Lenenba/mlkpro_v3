<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\PlanScan;
use App\Models\Quote;
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
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');
    config()->set('billing.provider_effective', 'paddle');
});

function fakeAssistantPlanScanOpenAi(array $payload): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-assistant-plan-scan',
            'model' => 'gpt-4.1-mini',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1100,
                'completion_tokens' => 160,
                'total_tokens' => 1260,
            ],
        ], 200),
    ]);
}

function fakeAssistantPlanScanConversationOpenAi(array $payload, string $model = 'gpt-4o-mini'): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-assistant-plan-scan-conversation',
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
                'prompt_tokens' => 650,
                'completion_tokens' => 120,
                'total_tokens' => 770,
            ],
        ], 200),
    ]);
}

function assistantPlanScanOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'assistant' => true,
            'plan_scans' => true,
            'quotes' => true,
        ],
        'locale' => 'fr',
    ], $overrides));
}

test('assistant can create a plan scan from a chat attachment', function () {
    Storage::fake('public');

    fakeAssistantPlanScanOpenAi([
        'document_type' => 'plan',
        'trade_guess' => 'plumbing',
        'metrics' => [
            'surface_m2_estimate' => 98.2,
            'room_count_estimate' => 5,
        ],
        'assumptions' => [
            'Wet areas estimated from room layout.',
        ],
        'review_flags' => [
            'Confirm fixture count in the back area.',
        ],
        'detected_lines' => [
            [
                'name' => 'Installation point d eau',
                'quantity' => 3,
                'unit' => 'u',
                'line_type' => 'service',
                'is_labor' => true,
                'confidence' => 81,
                'notes' => 'Detected from plumbing symbols.',
            ],
        ],
        'detected_elements' => ['water points'],
        'confidence' => [
            'overall' => 84,
            'trade' => 92,
            'surface' => 76,
            'rooms' => 70,
        ],
    ]);

    $owner = assistantPlanScanOwner();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Northwind Habitat',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'message' => 'Analyse ce plan de plomberie et prepare le scan.',
            'context' => [
                'current_customer' => [
                    'id' => $customer->id,
                ],
            ],
            'attachment' => UploadedFile::fake()->image('assistant-plan.png', 1400, 1000),
        ]);

    $scan = PlanScan::query()->first();

    $response->assertOk()
        ->assertJsonPath('status', 'plan_scan_ready')
        ->assertJsonPath('action.type', 'plan_scan_created')
        ->assertJsonPath('plan_scan.id', $scan?->id)
        ->assertJsonPath('plan_scan.customer_name', 'Northwind Habitat')
        ->assertJsonPath('plan_scan.trade_type', 'plumbing');

    expect($scan)->not->toBeNull()
        ->and($scan->status)->toBe('ready')
        ->and($scan->customer_id)->toBe($customer->id)
        ->and($scan->ai_status)->toBe('completed')
        ->and($scan->trade_type)->toBe('plumbing')
        ->and((float) $scan->metrics['surface_m2'])->toBe(98.2)
        ->and($scan->metrics['rooms'])->toBe(5);

    Storage::disk('public')->assertExists($scan->plan_file_path);
});

test('assistant can create a draft quote from an attached plan when the customer is known', function () {
    Storage::fake('public');

    fakeAssistantPlanScanOpenAi([
        'document_type' => 'plan',
        'trade_guess' => 'plumbing',
        'metrics' => [
            'surface_m2_estimate' => 72,
            'room_count_estimate' => 4,
        ],
        'assumptions' => [
            'Fixture locations inferred from the visible plan.',
        ],
        'review_flags' => [],
        'detected_lines' => [
            [
                'name' => 'Pack lavabo premium',
                'quantity' => 2,
                'unit' => 'u',
                'line_type' => 'service',
                'is_labor' => false,
                'confidence' => 88,
                'notes' => 'Two vanity zones detected.',
            ],
        ],
        'detected_elements' => ['vanity areas'],
        'confidence' => [
            'overall' => 94,
            'trade' => 96,
            'surface' => 91,
            'rooms' => 90,
        ],
    ]);

    $owner = assistantPlanScanOwner();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Atelier Horizon',
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('assistant.message'), [
            'message' => 'A partir de ce plan, cree aussi un devis pour ce client.',
            'context' => [
                'current_customer' => [
                    'id' => $customer->id,
                ],
            ],
            'attachment' => UploadedFile::fake()->image('assistant-quote-plan.png', 1400, 1000),
        ]);

    $scan = PlanScan::query()->firstOrFail();
    $quote = Quote::query()->first();

    $response->assertOk()
        ->assertJsonPath('status', 'plan_scan_quote_created')
        ->assertJsonPath('action.type', 'plan_scan_quote_created')
        ->assertJsonPath('action.plan_scan_id', $scan->id)
        ->assertJsonPath('action.quote_id', $quote?->id)
        ->assertJsonPath('quote.id', $quote?->id);

    expect($quote)->not->toBeNull()
        ->and($quote->customer_id)->toBe($customer->id)
        ->and($quote->status)->toBe('draft')
        ->and($scan->quotes_generated)->toBe(1);
});

test('assistant can refine the current plan scan through follow-up chat instructions', function () {
    $owner = assistantPlanScanOwner();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Northwind Habitat',
    ]);

    $scan = PlanScan::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Conversation review scan',
        'trade_type' => 'plumbing',
        'status' => 'ready',
        'ai_status' => 'completed',
        'ai_review_required' => true,
        'metrics' => [
            'surface_m2' => 72,
            'rooms' => 2,
            'priority' => 'balanced',
        ],
        'analysis' => [
            'summary' => 'Initial AI extraction.',
            'ai' => [
                'status' => 'completed',
            ],
        ],
        'variants' => [
            [
                'key' => 'standard',
                'label' => 'Standard',
                'items' => [
                    [
                        'name' => 'Pose lavabo',
                        'quantity' => 1,
                        'unit' => 'u',
                        'unit_cost' => 120,
                        'unit_price' => 180,
                        'total' => 180,
                    ],
                    [
                        'name' => 'Pack baignoire',
                        'quantity' => 1,
                        'unit' => 'u',
                        'unit_cost' => 240,
                        'unit_price' => 320,
                        'total' => 320,
                    ],
                ],
            ],
        ],
        'ai_reviewed_payload' => [
            'trade_type' => 'plumbing',
            'metrics' => [
                'surface_m2' => 72,
                'rooms' => 2,
                'priority' => 'balanced',
            ],
            'line_items' => [
                [
                    'name' => 'Pose lavabo',
                    'quantity' => 1,
                    'unit' => 'u',
                    'base_cost' => 120,
                    'is_labor' => true,
                    'confidence' => 82,
                    'notes' => 'Initial AI suggestion',
                ],
                [
                    'name' => 'Pack baignoire',
                    'quantity' => 1,
                    'unit' => 'u',
                    'base_cost' => 240,
                    'is_labor' => false,
                    'confidence' => 74,
                    'notes' => 'Initial AI suggestion',
                ],
            ],
            'assumptions' => [],
            'review_flags' => ['Review bathroom scope.'],
        ],
        'confidence_score' => 81,
    ]);

    fakeAssistantPlanScanConversationOpenAi([
        'intent' => 'review_scan',
        'summary' => 'J ai mis le scan a jour avec 3 pieces et retire la baignoire.',
        'trade_type' => null,
        'surface_m2' => null,
        'rooms' => 3,
        'priority' => 'quality',
        'variant_preference' => null,
        'remove_line_names' => ['Pack baignoire'],
        'upsert_lines' => [
            [
                'match_name' => 'Pose lavabo',
                'name' => 'Pose lavabo',
                'quantity' => 2,
                'unit' => 'u',
                'base_cost' => 130,
                'is_labor' => true,
                'description' => 'Updated from chat',
                'notes' => 'Client asked for two sinks',
            ],
        ],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('assistant.message'), [
            'message' => 'Considere plutot 3 pieces, retire la baignoire et mets 2 lavabos en qualite.',
            'context' => [
                'current_plan_scan' => [
                    'id' => $scan->id,
                ],
            ],
        ]);

    $scan->refresh();

    $response->assertOk()
        ->assertJsonPath('status', 'plan_scan_review_updated')
        ->assertJsonPath('action.type', 'plan_scan_updated')
        ->assertJsonPath('plan_scan.assistant_state_label', 'Reviewed in chat');

    expect($scan->ai_review_required)->toBeFalse()
        ->and(data_get($scan->ai_reviewed_payload, 'metrics.rooms'))->toBe(3)
        ->and(data_get($scan->ai_reviewed_payload, 'metrics.priority'))->toBe('quality')
        ->and(count(data_get($scan->ai_reviewed_payload, 'line_items', [])))->toBe(1)
        ->and((float) data_get($scan->ai_reviewed_payload, 'line_items.0.quantity'))->toBe(2.0)
        ->and(data_get($scan->analysis, 'ai.review_source'))->toBe('assistant_chat');
});

test('assistant can refine the current scan and create a draft quote in the same follow-up', function () {
    $owner = assistantPlanScanOwner();
    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Atelier Horizon',
    ]);

    $scan = PlanScan::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Conversation quote scan',
        'trade_type' => 'plumbing',
        'status' => 'ready',
        'ai_status' => 'completed',
        'ai_review_required' => true,
        'metrics' => [
            'surface_m2' => 80,
            'rooms' => 4,
            'priority' => 'balanced',
        ],
        'analysis' => [
            'summary' => 'Initial AI extraction.',
            'ai' => [
                'status' => 'completed',
            ],
        ],
        'variants' => [
            [
                'key' => 'standard',
                'label' => 'Standard',
                'items' => [
                    [
                        'name' => 'Pack lavabo',
                        'quantity' => 2,
                        'unit' => 'u',
                        'unit_cost' => 100,
                        'unit_price' => 160,
                        'total' => 320,
                    ],
                ],
            ],
            [
                'key' => 'premium',
                'label' => 'Premium',
                'items' => [
                    [
                        'name' => 'Pack lavabo premium',
                        'quantity' => 2,
                        'unit' => 'u',
                        'unit_cost' => 160,
                        'unit_price' => 240,
                        'total' => 480,
                    ],
                ],
            ],
        ],
        'ai_reviewed_payload' => [
            'trade_type' => 'plumbing',
            'metrics' => [
                'surface_m2' => 80,
                'rooms' => 4,
                'priority' => 'balanced',
            ],
            'line_items' => [
                [
                    'name' => 'Pack lavabo premium',
                    'quantity' => 2,
                    'unit' => 'u',
                    'base_cost' => 160,
                    'is_labor' => false,
                    'confidence' => 88,
                    'notes' => 'Premium pack',
                ],
            ],
            'assumptions' => [],
            'review_flags' => ['Review customer layout.'],
        ],
        'confidence_score' => 84,
    ]);

    fakeAssistantPlanScanConversationOpenAi([
        'intent' => 'review_and_create_quote',
        'summary' => 'J ai passe le scan en premium puis cree le devis brouillon.',
        'trade_type' => null,
        'surface_m2' => null,
        'rooms' => 5,
        'priority' => 'quality',
        'variant_preference' => 'premium',
        'remove_line_names' => [],
        'upsert_lines' => [],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('assistant.message'), [
            'message' => 'Passe ce scan en premium avec 5 pieces puis cree le devis.',
            'context' => [
                'current_plan_scan' => [
                    'id' => $scan->id,
                ],
            ],
        ]);

    $scan->refresh();
    $quote = Quote::query()->first();

    $response->assertOk()
        ->assertJsonPath('status', 'plan_scan_quote_created')
        ->assertJsonPath('action.type', 'plan_scan_quote_created')
        ->assertJsonPath('quote.id', $quote?->id)
        ->assertJsonPath('plan_scan.assistant_state_label', 'Quote draft created');

    expect($scan->ai_review_required)->toBeFalse()
        ->and(data_get($scan->ai_reviewed_payload, 'metrics.rooms'))->toBe(5)
        ->and(data_get($scan->ai_reviewed_payload, 'metrics.priority'))->toBe('quality')
        ->and($quote)->not->toBeNull()
        ->and($quote->customer_id)->toBe($customer->id)
        ->and($quote->status)->toBe('draft');
});
