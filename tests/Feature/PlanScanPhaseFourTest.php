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

function fakePlanScanPhaseFourOpenAiByRequestedModel(array $responses): void
{
    Http::fake([
        'https://api.openai.com/v1/chat/completions' => function ($request) use ($responses) {
            $payload = json_decode($request->body(), true);
            $requestedModel = $payload['model'] ?? 'gpt-4.1-mini';
            $resolved = $responses[$requestedModel] ?? reset($responses);

            return Http::response([
                'id' => 'chatcmpl-plan-scan-phase-four',
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

function identicalPlanPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO0pL5QAAAAASUVORK5CYII=');
}

test('plan scan automatically falls back to the stronger model for low-confidence scans', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');
    config()->set('services.openai.plan_scan_fallback_model', 'gpt-4.1');
    config()->set('services.openai.plan_scan_primary_input_cost_per_1m', 0.2);
    config()->set('services.openai.plan_scan_primary_output_cost_per_1m', 0.8);
    config()->set('services.openai.plan_scan_fallback_input_cost_per_1m', 1.0);
    config()->set('services.openai.plan_scan_fallback_output_cost_per_1m', 4.0);

    fakePlanScanPhaseFourOpenAiByRequestedModel([
        'gpt-4.1-mini' => [
            'document_type' => 'plan',
            'trade_guess' => 'general',
            'metrics' => [
                'surface_m2_estimate' => null,
                'room_count_estimate' => null,
            ],
            'assumptions' => [
                'Primary model could not resolve enough dimensions.',
            ],
            'review_flags' => [
                'Escalate analysis for a stronger read.',
            ],
            'detected_lines' => [],
            'detected_elements' => [],
            'confidence' => [
                'overall' => 41,
                'trade' => 47,
                'surface' => 20,
                'rooms' => 20,
            ],
        ],
        'gpt-4.1' => [
            'document_type' => 'plan',
            'trade_guess' => 'plumbing',
            'metrics' => [
                'surface_m2_estimate' => 96,
                'room_count_estimate' => 5,
            ],
            'assumptions' => [
                'Fallback model resolved the wet areas clearly.',
            ],
            'review_flags' => [],
            'detected_lines' => [
                [
                    'name' => 'Installation plomberie premium',
                    'quantity' => 5,
                    'unit' => 'u',
                    'line_type' => 'service',
                    'is_labor' => true,
                    'confidence' => 94,
                    'notes' => 'Recovered after automatic fallback.',
                ],
            ],
            'detected_elements' => ['wet areas', 'service lines'],
            'confidence' => [
                'overall' => 94,
                'trade' => 97,
                'surface' => 92,
                'rooms' => 91,
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

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->image('fallback-plan.png', 1400, 1000),
            'job_title' => 'Fallback candidate',
            'trade_type' => 'plumbing',
            'priority' => 'balanced',
        ]);

    $scan = PlanScan::query()->firstOrFail();

    $response->assertRedirect(route('plan-scans.show', $scan))
        ->assertSessionHas('success', 'Plan scan ready.');

    Http::assertSentCount(2);

    expect($scan->ai_model)->toBe('gpt-4.1')
        ->and($scan->ai_cache_hit)->toBeFalse()
        ->and($scan->ai_review_required)->toBeFalse()
        ->and(data_get($scan->ai_extraction_normalized, 'recommended_action'))->toBe('ready')
        ->and((int) data_get($scan->ai_usage, 'attempt_count'))->toBe(2)
        ->and((int) data_get($scan->ai_usage, 'total_tokens'))->toBe(2760)
        ->and(count($scan->ai_attempts ?? []))->toBe(2)
        ->and(data_get($scan->ai_attempts, '0.source'))->toBe('primary')
        ->and(data_get($scan->ai_attempts, '1.source'))->toBe('fallback')
        ->and((float) $scan->ai_estimated_cost_usd)->toBeGreaterThan(0.002)
        ->and((float) $scan->ai_estimated_cost_usd)->toBeLessThan(0.003)
        ->and(data_get($scan->analysis, 'ai.cache_hit'))->toBeFalse()
        ->and(data_get($scan->analysis, 'ai.attempts.1.model'))->toBe('gpt-4.1');
});

test('plan scan reuses cached extraction for repeated identical uploads', function () {
    Storage::fake('public');

    config()->set('services.openai.key', 'test-openai-key');
    config()->set('services.openai.plan_scan_model', 'gpt-4.1-mini');
    config()->set('services.openai.plan_scan_fallback_model', 'gpt-4.1');
    config()->set('services.openai.plan_scan_cache_ttl', 1440);
    config()->set('services.openai.plan_scan_primary_input_cost_per_1m', 0.2);
    config()->set('services.openai.plan_scan_primary_output_cost_per_1m', 0.8);

    fakePlanScanPhaseFourOpenAiByRequestedModel([
        'gpt-4.1-mini' => [
            'document_type' => 'plan',
            'trade_guess' => 'painting',
            'metrics' => [
                'surface_m2_estimate' => 55,
                'room_count_estimate' => 3,
            ],
            'assumptions' => [
                'Repeatable test payload.',
            ],
            'review_flags' => [],
            'detected_lines' => [
                [
                    'name' => 'Peinture murs',
                    'quantity' => 55,
                    'unit' => 'm2',
                    'line_type' => 'service',
                    'is_labor' => true,
                    'confidence' => 89,
                    'notes' => 'Stable repeated scan payload.',
                ],
            ],
            'detected_elements' => ['walls'],
            'confidence' => [
                'overall' => 88,
                'trade' => 91,
                'surface' => 86,
                'rooms' => 84,
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

    $firstResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->createWithContent('identical-plan.png', identicalPlanPng()),
            'job_title' => 'First identical upload',
            'trade_type' => 'painting',
            'priority' => 'balanced',
        ]);

    $firstScan = PlanScan::query()->oldest('id')->firstOrFail();

    $secondResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->post(route('plan-scans.store'), [
            'plan_file' => UploadedFile::fake()->createWithContent('identical-plan.png', identicalPlanPng()),
            'job_title' => 'Second identical upload',
            'trade_type' => 'painting',
            'priority' => 'balanced',
        ]);

    $scans = PlanScan::query()->orderBy('id')->get();
    $firstScan = $scans[0];
    $secondScan = $scans[1];

    $firstResponse->assertRedirect(route('plan-scans.show', $firstScan))
        ->assertSessionHas('success', 'Plan scan ready.');

    $secondResponse->assertRedirect(route('plan-scans.show', $secondScan))
        ->assertSessionHas('success', 'Plan scan ready.');

    Http::assertSentCount(1);

    expect($firstScan->ai_cache_hit)->toBeFalse()
        ->and($secondScan->ai_cache_hit)->toBeTrue()
        ->and($secondScan->ai_model)->toBe('gpt-4.1-mini')
        ->and((int) data_get($secondScan->ai_usage, 'total_tokens'))->toBe(0)
        ->and((float) $secondScan->ai_estimated_cost_usd)->toBe(0.0)
        ->and(data_get($secondScan->ai_attempts, '0.source'))->toBe('cache')
        ->and(collect(['runtime_cache', 'scan:'.$firstScan->id])->contains($secondScan->ai_cache_source))->toBeTrue();
});
