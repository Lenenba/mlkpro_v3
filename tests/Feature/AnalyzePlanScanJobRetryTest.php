<?php

use App\Jobs\AnalyzePlanScanJob;
use App\Models\ActivityLog;
use App\Models\PlanScan;
use App\Models\User;
use App\Services\PlanScanAiPipelineService;
use App\Services\PlanScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('database queue payload preserves plan scan retry and timeout metadata', function () {
    config()->set('queue.default', 'database');
    config()->set('async.workloads.plan_scans.queue', 'plan-scans');
    config()->set('async.workloads.plan_scans.backoff', [60]);
    config()->set('async.workloads.plan_scans.timeout', 240);

    AnalyzePlanScanJob::dispatch(123, 456);

    $storedJob = DB::table('jobs')->sole();
    $payload = json_decode($storedJob->payload, true, 512, JSON_THROW_ON_ERROR);
    $queuedJob = unserialize($payload['data']['command']);

    expect($storedJob->queue)->toBe('plan-scans')
        ->and($payload['maxTries'])->toBe(2)
        ->and($payload['timeout'])->toBe(240)
        ->and($payload['backoff'])->toBe('60')
        ->and($queuedJob)->toBeInstanceOf(AnalyzePlanScanJob::class)
        ->and($queuedJob->queue)->toBe('plan-scans')
        ->and($queuedJob->tries)->toBe(2)
        ->and($queuedJob->timeout)->toBe(240)
        ->and($queuedJob->backoff())->toBe([60]);
});

test('plan scan analysis failures are rethrown so the queue can retry them', function () {
    [$owner, $scan] = retryablePlanScan();

    $pipeline = Mockery::mock(PlanScanAiPipelineService::class);
    $pipeline->shouldReceive('run')
        ->once()
        ->andReturn(planScanExtractionForRetryTest());

    $planScanService = Mockery::mock(PlanScanService::class);
    $planScanService->shouldReceive('analyze')
        ->once()
        ->andThrow(new RuntimeException('Temporary pricing outage'));

    $job = new AnalyzePlanScanJob($scan->id, $owner->id, [
        'surface_m2' => 84,
        'rooms' => 5,
        'priority' => 'balanced',
    ]);

    expect(fn () => $job->handle($planScanService, $pipeline))
        ->toThrow(RuntimeException::class, 'Temporary pricing outage');

    $scan->refresh();

    expect($job->tries)->toBe(2)
        ->and($job->backoff())->toBe([60])
        ->and($scan->status)->toBe(PlanScanService::STATUS_PROCESSING)
        ->and($scan->ai_status)->toBe('retrying')
        ->and($scan->ai_review_required)->toBeTrue()
        ->and($scan->ai_failed_at)->toBeNull()
        ->and($scan->ai_error_message)->toBe('Temporary pricing outage')
        ->and($scan->error_message)->toBe('Temporary pricing outage');
});

test('plan scan failed hook records a terminal queue failure', function () {
    [$owner, $scan] = retryablePlanScan();
    $job = new AnalyzePlanScanJob($scan->id, $owner->id);

    $job->failed(new RuntimeException('Permanent plan scan failure'));
    $scan->refresh();

    expect($scan->status)->toBe(PlanScanService::STATUS_FAILED)
        ->and($scan->ai_status)->toBe('failed')
        ->and($scan->ai_review_required)->toBeTrue()
        ->and($scan->ai_failed_at)->not->toBeNull()
        ->and($scan->ai_error_message)->toBe('Permanent plan scan failure')
        ->and($scan->error_message)->toBe('Permanent plan scan failure');
});

test('activity log failures stay best effort after a successful analysis', function () {
    [$owner, $scan] = retryablePlanScan();

    $pipeline = Mockery::mock(PlanScanAiPipelineService::class);
    $pipeline->shouldReceive('run')
        ->once()
        ->andReturn(planScanExtractionForRetryTest());

    $planScanService = Mockery::mock(PlanScanService::class);
    $planScanService->shouldReceive('analyze')
        ->once()
        ->andReturn([
            'metrics' => [
                'surface_m2' => 84,
                'rooms' => 5,
                'priority' => 'balanced',
            ],
            'analysis' => ['assumptions' => []],
            'variants' => [],
            'confidence_score' => 90,
        ]);

    $activityCreatingEvent = 'eloquent.creating: '.ActivityLog::class;
    Event::listen($activityCreatingEvent, static function (): never {
        throw new RuntimeException('Activity log storage unavailable');
    });

    try {
        (new AnalyzePlanScanJob($scan->id, $owner->id, [
            'surface_m2' => 84,
            'rooms' => 5,
            'priority' => 'balanced',
        ]))->handle($planScanService, $pipeline);
    } finally {
        Event::forget($activityCreatingEvent);
    }

    $scan->refresh();

    expect($scan->status)->toBe(PlanScanService::STATUS_READY)
        ->and($scan->ai_status)->toBe('completed')
        ->and($scan->ai_review_required)->toBeFalse()
        ->and($scan->ai_failed_at)->toBeNull()
        ->and($scan->error_message)->toBeNull()
        ->and($scan->analyzed_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('subject_id', $scan->id)->exists())->toBeFalse();
});

/**
 * @return array{0: User, 1: PlanScan}
 */
function retryablePlanScan(): array
{
    $owner = User::factory()->create();
    $scan = PlanScan::query()->create([
        'user_id' => $owner->id,
        'job_title' => 'Retry reliability test',
        'trade_type' => 'plumbing',
        'status' => PlanScanService::STATUS_NEW,
        'ai_status' => 'queued',
        'plan_file_name' => 'retry-test.pdf',
    ]);

    return [$owner, $scan];
}

/**
 * @return array<string, mixed>
 */
function planScanExtractionForRetryTest(): array
{
    return [
        'status' => 'completed',
        'model' => 'test-plan-model',
        'usage' => ['total_tokens' => 100],
        'raw' => ['source' => 'test'],
        'normalized' => [
            'trade_guess' => 'plumbing',
            'metrics' => [
                'surface_m2_estimate' => 84,
                'room_count_estimate' => 5,
            ],
            'assumptions' => [],
            'review_flags' => [],
            'field_flags' => [],
            'detected_lines' => [],
            'confidence' => ['overall' => 90],
        ],
        'review_required' => false,
        'error_message' => null,
        'attempts' => [],
        'cache_key' => null,
        'cache_hit' => false,
        'cache_source' => null,
        'estimated_cost_usd' => 0.001,
    ];
}
