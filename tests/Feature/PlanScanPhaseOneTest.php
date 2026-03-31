<?php

use App\Models\PlanScan;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function fakePlanScanOpenAi(array $payload, string $model = 'gpt-4.1-mini'): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-plan-scan',
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
                'prompt_tokens' => 1200,
                'completion_tokens' => 180,
                'total_tokens' => 1380,
            ],
        ], 200),
    ]);
}

function fakePlanScanOpenAiByRequestedModel(array $responses): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => function ($request) use ($responses) {
            $payload = json_decode($request->body(), true);
            $requestedModel = $payload['model'] ?? 'gpt-4.1-mini';
            $resolved = $responses[$requestedModel] ?? reset($responses);

            return Http::response([
                'id' => 'chatcmpl-plan-scan',
                'model' => $requestedModel,
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($resolved, JSON_THROW_ON_ERROR),
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 1200,
                    'completion_tokens' => 180,
                    'total_tokens' => 1380,
                ],
            ], 200);
        },
    ]);
}

test('plan scan store enriches metrics with ai extraction when openai is configured', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');

    fakePlanScanOpenAi([
        'document_type' => 'plan',
        'trade_guess' => 'plumbing',
        'metrics' => [
            'surface_m2_estimate' => 126.4,
            'room_count_estimate' => 6,
        ],
        'assumptions' => [
            'Surface estimated from visible room geometry.',
        ],
        'review_flags' => [
            'Verify plumbing fixture count on the lower-right area.',
        ],
        'detected_lines' => [
            [
                'name' => 'Installation point d eau',
                'quantity' => 4,
                'unit' => 'u',
                'line_type' => 'service',
                'is_labor' => true,
                'confidence' => 84,
                'notes' => 'Detected from wet areas.',
            ],
        ],
        'detected_elements' => [
            'water points',
            'sink areas',
        ],
        'confidence' => [
            'overall' => 82,
            'trade' => 91,
            'surface' => 76,
            'rooms' => 73,
        ],
    ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'plan_scans' => true,
            'quotes' => true,
        ],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->image('floor-plan.png', 1400, 1000),
            'job_title' => 'Kitchen plumbing refit',
            'trade_type' => 'plumbing',
            'priority' => 'balanced',
        ]);

    $scan = PlanScan::query()->first();

    $response->assertRedirect(route('plan-scans.show', $scan))
        ->assertSessionHas('success', 'Plan scan ready.');

    expect($scan)->not->toBeNull()
        ->and($scan->status)->toBe('ready')
        ->and($scan->ai_status)->toBe('completed')
        ->and($scan->ai_model)->toBe('gpt-4.1-mini')
        ->and($scan->metrics['surface_m2'])->toBe(126.4)
        ->and($scan->metrics['rooms'])->toBe(6)
        ->and($scan->confidence_score)->toBeGreaterThanOrEqual(82)
        ->and($scan->ai_review_required)->toBeTrue()
        ->and(data_get($scan->ai_extraction_normalized, 'trade_guess'))->toBe('plumbing')
        ->and(data_get($scan->ai_extraction_normalized, 'metrics.surface_m2_estimate'))->toBe(126.4)
        ->and(data_get($scan->ai_extraction_normalized, 'field_flags.0.field'))->toBe('trade_guess')
        ->and(data_get($scan->ai_extraction_normalized, 'recommended_action'))->toBe('review')
        ->and(data_get($scan->ai_reviewed_payload, 'line_items.0.name'))->toBe('Installation point d eau')
        ->and(data_get($scan->analysis, 'ai.status'))->toBe('completed')
        ->and(data_get($scan->analysis, 'summary'))->toContain('lecture IA');

    Storage::disk('public')->assertExists($scan->plan_file_path);
});

test('plan scan falls back to manual metrics when ai extraction fails', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'Temporary upstream issue.',
                'type' => 'server_error',
            ],
        ], 500),
    ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'plan_scans' => true,
            'quotes' => true,
        ],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->image('bathroom-plan.png', 1200, 900),
            'job_title' => 'Bathroom refresh',
            'trade_type' => 'plumbing',
            'surface_m2' => 88,
            'rooms' => 5,
            'priority' => 'balanced',
        ]);

    $scan = PlanScan::query()->first();

    $response->assertRedirect(route('plan-scans.show', $scan))
        ->assertSessionHas('success', 'Plan scan ready.');

    expect($scan)->not->toBeNull()
        ->and($scan->status)->toBe('ready')
        ->and($scan->ai_status)->toBe('failed')
        ->and($scan->ai_review_required)->toBeTrue()
        ->and((float) $scan->metrics['surface_m2'])->toBe(88.0)
        ->and($scan->metrics['rooms'])->toBe(5)
        ->and($scan->ai_error_message)->toContain('Temporary upstream issue')
        ->and(data_get($scan->ai_extraction_normalized, 'review_flags.0'))->toContain('AI extraction failed')
        ->and(data_get($scan->analysis, 'ai.status'))->toBe('failed');
});

test('plan scan review regenerates variants from reviewed line items', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');

    fakePlanScanOpenAi([
        'document_type' => 'plan',
        'trade_guess' => 'plumbing',
        'metrics' => [
            'surface_m2_estimate' => 64,
            'room_count_estimate' => 3,
        ],
        'assumptions' => [
            'Initial line items are only a first pass.',
        ],
        'review_flags' => [
            'Verify custom fixture quantities.',
        ],
        'detected_lines' => [
            [
                'name' => 'Pose lavabo',
                'quantity' => 2,
                'unit' => 'u',
                'line_type' => 'service',
                'is_labor' => true,
                'confidence' => 78,
                'notes' => 'Detected from two vanity areas.',
            ],
        ],
        'detected_elements' => ['lavabo areas'],
        'confidence' => [
            'overall' => 79,
            'trade' => 88,
            'surface' => 72,
            'rooms' => 68,
        ],
    ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'plan_scans' => true,
            'quotes' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->image('review-plan.png', 1200, 900),
            'job_title' => 'Reviewable plan',
            'trade_type' => 'plumbing',
            'priority' => 'balanced',
        ])
        ->assertRedirect();

    $scan = PlanScan::query()->firstOrFail();

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patch(route('plan-scans.review', $scan), [
            'trade_type' => 'plumbing',
            'surface_m2' => 70,
            'rooms' => 4,
            'priority' => 'quality',
            'line_items' => [
                [
                    'name' => 'Custom fixture package',
                    'quantity' => 7,
                    'unit' => 'u',
                    'description' => 'Reviewed package from AI extraction',
                    'base_cost' => 120,
                    'is_labor' => false,
                    'confidence' => 90,
                    'notes' => 'Validated during review',
                ],
            ],
        ]);

    $scan->refresh();

    $response->assertRedirect(route('plan-scans.show', $scan))
        ->assertSessionHas('success', 'Plan scan review saved.');

    expect($scan->trade_type)->toBe('plumbing')
        ->and((float) $scan->metrics['surface_m2'])->toBe(70.0)
        ->and($scan->metrics['rooms'])->toBe(4)
        ->and($scan->metrics['priority'])->toBe('quality')
        ->and(data_get($scan->ai_reviewed_payload, 'line_items.0.name'))->toBe('Custom fixture package')
        ->and(data_get($scan->analysis, 'extraction_mode'))->toBe('reviewed_lines')
        ->and(data_get($scan->analysis, 'ai.reviewed'))->toBeTrue()
        ->and(data_get($scan->variants, '0.items.0.name'))->toBe('Custom fixture package')
        ->and((float) data_get($scan->variants, '0.items.0.quantity'))->toBe(7.0)
        ->and((float) data_get($scan->variants, '1.items.0.unit_cost'))->toBe(120.0);
});

test('plan scan requiring review cannot convert to a quote until review is saved', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');

    fakePlanScanOpenAi([
        'document_type' => 'plan',
        'trade_guess' => 'plumbing',
        'metrics' => [
            'surface_m2_estimate' => 61,
            'room_count_estimate' => 3,
        ],
        'assumptions' => [
            'Some fixtures may be hidden by annotations.',
        ],
        'review_flags' => [
            'Validate the plumbing scope before quoting.',
        ],
        'detected_lines' => [
            [
                'name' => 'Pose lavabo',
                'quantity' => 1,
                'unit' => 'u',
                'line_type' => 'service',
                'is_labor' => true,
                'confidence' => 67,
                'notes' => 'Moderate confidence.',
            ],
        ],
        'detected_elements' => ['lavabo area'],
        'confidence' => [
            'overall' => 72,
            'trade' => 88,
            'surface' => 63,
            'rooms' => 60,
        ],
    ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'plan_scans' => true,
            'quotes' => true,
        ],
    ]);

    $customer = \App\Models\Customer::factory()->create([
        'user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->image('review-required.png', 1200, 900),
            'job_title' => 'Review required scan',
            'trade_type' => 'plumbing',
            'customer_id' => $customer->id,
            'priority' => 'balanced',
        ])
        ->assertRedirect();

    $scan = PlanScan::query()->firstOrFail();

    expect($scan->ai_review_required)->toBeTrue();

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.convert', $scan), [
            'variant' => 'standard',
        ]);

    $response->assertSessionHasErrors('review');
});

test('plan scan can retry with fallback model when escalation is requested', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');
    config()->set('services.openai.plan_scan_fallback_model', 'gpt-4.1');

    fakePlanScanOpenAiByRequestedModel([
        'gpt-4.1-mini' => [
            'document_type' => 'plan',
            'trade_guess' => 'plumbing',
            'metrics' => [
                'surface_m2_estimate' => 81,
                'room_count_estimate' => 4,
            ],
            'assumptions' => [
                'First pass is usable but should still be confirmed before quoting.',
            ],
            'review_flags' => [
                'Validate the fixture count before quoting.',
            ],
            'detected_lines' => [
                [
                    'name' => 'Installation plomberie standard',
                    'quantity' => 3,
                    'unit' => 'u',
                    'line_type' => 'service',
                    'is_labor' => true,
                    'confidence' => 72,
                    'notes' => 'Usable first pass with moderate confidence.',
                ],
            ],
            'detected_elements' => ['wet areas'],
            'confidence' => [
                'overall' => 72,
                'trade' => 84,
                'surface' => 66,
                'rooms' => 63,
            ],
        ],
        'gpt-4.1' => [
            'document_type' => 'plan',
            'trade_guess' => 'plumbing',
            'metrics' => [
                'surface_m2_estimate' => 84,
                'room_count_estimate' => 5,
            ],
            'assumptions' => [
                'Escalated model resolved the wet areas more clearly.',
            ],
            'review_flags' => [],
            'detected_lines' => [
                [
                    'name' => 'Installation plomberie premium',
                    'quantity' => 4,
                    'unit' => 'u',
                    'line_type' => 'service',
                    'is_labor' => true,
                    'confidence' => 93,
                    'notes' => 'High confidence after escalation.',
                ],
            ],
            'detected_elements' => ['wet areas', 'service lines'],
            'confidence' => [
                'overall' => 93,
                'trade' => 96,
                'surface' => 91,
                'rooms' => 90,
            ],
        ],
    ]);

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'plan_scans' => true,
            'quotes' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->image('escalate-plan.png', 1200, 900),
            'job_title' => 'Escalation candidate',
            'trade_type' => 'plumbing',
            'priority' => 'balanced',
        ])
        ->assertRedirect();

    $scan = PlanScan::query()->firstOrFail();

    expect($scan->ai_review_required)->toBeTrue()
        ->and($scan->ai_model)->toBe('gpt-4.1-mini')
        ->and(data_get($scan->ai_extraction_normalized, 'recommended_action'))->toBe('review');

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.reanalyze', $scan), [
            'mode' => 'escalate',
        ]);

    $scan->refresh();

    $response->assertRedirect(route('plan-scans.show', $scan))
        ->assertSessionHas('success', 'AI analysis escalated and refreshed.');

    expect($scan->ai_retry_count)->toBe(1)
        ->and($scan->ai_escalated_at)->not->toBeNull()
        ->and($scan->ai_model)->toBe('gpt-4.1')
        ->and($scan->ai_review_required)->toBeFalse()
        ->and(data_get($scan->ai_extraction_normalized, 'recommended_action'))->toBe('ready')
        ->and(data_get($scan->analysis, 'ai.requested_mode'))->toBe('escalate')
        ->and(data_get($scan->ai_extraction_normalized, 'field_flags.0.status'))->toBe('ok');
});

test('plan scan can be deleted from the module', function () {
    Storage::fake('public');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'plan_scans' => true,
            'quotes' => true,
        ],
    ]);

    Storage::disk('public')->put('plan-scans/delete-me.pdf', 'scan payload');

    $scan = PlanScan::query()->create([
        'user_id' => $owner->id,
        'job_title' => 'Delete me',
        'trade_type' => 'general',
        'status' => 'failed',
        'plan_file_path' => 'plan-scans/delete-me.pdf',
        'plan_file_name' => 'delete-me.pdf',
    ]);

    expect(Storage::disk('public')->exists('plan-scans/delete-me.pdf'))->toBeTrue();

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->delete(route('plan-scans.destroy', $scan));

    $response->assertRedirect(route('plan-scans.index'))
        ->assertSessionHas('success', 'Plan scan deleted.');

    expect(PlanScan::query()->find($scan->id))->toBeNull()
        ->and(Storage::disk('public')->exists('plan-scans/delete-me.pdf'))->toBeFalse();
});
