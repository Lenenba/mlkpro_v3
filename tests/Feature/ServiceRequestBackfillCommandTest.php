<?php

use App\Models\ActivityLog;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('runs the service request backfill dry-run and separates eligible already-backfilled review and excluded legacy leads', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'web_form',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Need plumbing help',
        'service_type' => 'Plumbing',
        'contact_name' => 'Eligible Lead',
        'contact_email' => 'eligible@example.com',
    ]);

    $alreadyBackfilledLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'api',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Already synced',
        'service_type' => 'HVAC',
        'contact_name' => 'Already Backfilled',
        'contact_email' => 'already@example.com',
    ]);

    ServiceRequest::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $alreadyBackfilledLead->id,
        'source' => 'api',
        'channel' => 'api',
        'status' => ServiceRequest::STATUS_NEW,
        'title' => 'Already synced',
        'requester_name' => 'Already Backfilled',
        'requester_email' => 'already@example.com',
        'source_ref' => 'lead:'.$alreadyBackfilledLead->id,
        'submitted_at' => now(),
        'meta' => [
            'legacy_backfill' => [
                'batch_id' => 'existing-batch',
            ],
        ],
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'manual',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Cold prospect list',
        'contact_name' => 'Review Lead',
        'contact_email' => 'review@example.com',
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'email',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Outbound follow-up',
        'contact_name' => 'Outbound Prospect',
        'contact_email' => 'outbound@example.com',
        'meta' => [
            'source_kind' => 'campaign_prospecting',
        ],
    ]);

    $beforeCount = ServiceRequest::query()->count();

    $this->artisan('service-requests:backfill-dry-run', [
        '--account_id' => $owner->id,
    ])
        ->expectsOutputToContain('Service request backfill dry run')
        ->expectsOutputToContain('Scanned legacy requests: 4')
        ->expectsOutputToContain('Eligible legacy requests: 1')
        ->expectsOutputToContain('Already backfilled: 1')
        ->expectsOutputToContain('Needs review: 1')
        ->expectsOutputToContain('Excluded: 1')
        ->expectsOutputToContain('eligible.inbound_channel: 1')
        ->expectsOutputToContain('already_backfilled.existing_service_request: 1')
        ->expectsOutputToContain('review.prospect_like: 1')
        ->expectsOutputToContain('excluded.outbound_campaign_prospect: 1')
        ->assertExitCode(0);

    expect(ServiceRequest::query()->count())->toBe($beforeCount)
        ->and(ActivityLog::query()->count())->toBe(0);
});

it('backfills eligible legacy requests into service requests and preserves timestamps', function () {
    Storage::fake('local');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'web_form',
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'status_updated_at' => now()->subDays(8),
        'last_activity_at' => now()->subDays(6),
        'title' => 'Legacy quote request',
        'service_type' => 'Electrical',
        'description' => 'Need a quote for panel upgrade.',
        'contact_name' => 'Legacy Demand',
        'contact_email' => 'legacy-demand@example.com',
        'contact_phone' => '+1 555 4100',
    ]);

    $lead->timestamps = false;
    $lead->forceFill([
        'created_at' => now()->subDays(12),
        'updated_at' => now()->subDays(5),
    ])->save();
    $lead->timestamps = true;

    Quote::query()->create([
        'user_id' => $owner->id,
        'request_id' => $lead->id,
        'prospect_id' => $lead->id,
        'job_title' => 'Panel upgrade quote',
        'status' => 'sent',
        'subtotal' => 950,
        'total' => 950,
        'currency_code' => 'CAD',
    ]);

    $alreadyBackfilledLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'api',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Already backfilled',
        'service_type' => 'Cleaning',
        'contact_name' => 'Existing SR',
        'contact_email' => 'existing-sr@example.com',
    ]);

    ServiceRequest::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $alreadyBackfilledLead->id,
        'source' => 'api',
        'channel' => 'api',
        'status' => ServiceRequest::STATUS_NEW,
        'title' => 'Already backfilled',
        'requester_name' => 'Existing SR',
        'requester_email' => 'existing-sr@example.com',
        'source_ref' => 'lead:'.$alreadyBackfilledLead->id,
        'submitted_at' => now(),
    ]);

    $this->artisan('service-requests:backfill-run', [
        '--account_id' => $owner->id,
        '--force' => true,
    ])
        ->expectsOutputToContain('Service request backfill completed')
        ->expectsOutputToContain('Scanned legacy requests: 2')
        ->expectsOutputToContain('Eligible legacy requests: 1')
        ->expectsOutputToContain('Already backfilled: 1')
        ->expectsOutputToContain('Backfilled requests: 1')
        ->expectsOutputToContain('Failed requests: 0')
        ->assertExitCode(0);

    $serviceRequest = ServiceRequest::query()
        ->where('prospect_id', $lead->id)
        ->where('source_ref', 'lead:'.$lead->id)
        ->first();

    expect($serviceRequest)->not->toBeNull()
        ->and($serviceRequest?->status)->toBe(ServiceRequest::STATUS_IN_PROGRESS)
        ->and($serviceRequest?->request_type)->toBe('quote_request')
        ->and($serviceRequest?->requester_email)->toBe('legacy-demand@example.com')
        ->and($serviceRequest?->created_at?->getTimestamp())->toBe($lead->created_at?->getTimestamp())
        ->and($serviceRequest?->updated_at?->getTimestamp())->toBe($lead->updated_at?->getTimestamp())
        ->and(data_get($serviceRequest?->meta, 'legacy_backfill.reason'))->toBe('eligible.quote_attached');

    expect(ActivityLog::query()
        ->where('subject_type', $serviceRequest?->getMorphClass())
        ->where('subject_id', $serviceRequest?->id)
        ->where('action', 'legacy_service_request_backfilled')
        ->exists())->toBeTrue()
        ->and(ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('action', 'legacy_request_linked_to_service_request')
            ->exists())->toBeTrue();

    $files = collect(Storage::disk('local')->allFiles('service-request-backfills'));
    $summaryPath = $files->first(fn (string $path): bool => str_ends_with($path, 'summary.json'));
    $mappingPath = $files->first(fn (string $path): bool => str_ends_with($path, 'mappings.csv'));

    expect($summaryPath)->not->toBeNull()
        ->and($mappingPath)->not->toBeNull();

    $summary = json_decode((string) Storage::disk('local')->get((string) $summaryPath), true);
    $mappingCsv = (string) Storage::disk('local')->get((string) $mappingPath);

    expect($summary)->toBeArray()
        ->and(data_get($summary, 'backfilled_count'))->toBe(1)
        ->and(data_get($summary, 'failed_count'))->toBe(0)
        ->and($mappingCsv)->toContain('legacy_request_id')
        ->and($mappingCsv)->toContain((string) $lead->id);
});

it('verifies a service request backfill batch and surfaces remaining review work', function () {
    Storage::fake('local');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'web_form',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now()->subDays(4),
        'last_activity_at' => now()->subDays(4),
        'title' => 'Backfill me',
        'service_type' => 'Roofing',
        'contact_name' => 'Verified Lead',
        'contact_email' => 'verified@example.com',
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'manual',
        'status' => LeadRequest::STATUS_NEW,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Manual review prospect',
        'contact_name' => 'Review Pending',
        'contact_email' => 'review-pending@example.com',
    ]);

    $this->artisan('service-requests:backfill-run', [
        '--account_id' => $owner->id,
        '--force' => true,
    ])->assertExitCode(0);

    $serviceRequest = ServiceRequest::query()
        ->where('prospect_id', $lead->id)
        ->firstOrFail();

    $serviceRequest->forceFill([
        'source_ref' => 'broken-ref',
    ])->save();

    $this->artisan('service-requests:backfill-verify', [
        '--account_id' => $owner->id,
    ])
        ->expectsOutputToContain('Service request backfill verification')
        ->expectsOutputToContain('Backfilled legacy requests checked: 1')
        ->expectsOutputToContain('Verified requests: 0')
        ->expectsOutputToContain('Requests with issues: 1')
        ->expectsOutputToContain('Remaining eligible requests: 0')
        ->expectsOutputToContain('Remaining review requests: 1')
        ->expectsOutputToContain('service_request_source_ref_mismatch')
        ->expectsOutputToContain('Review Pending')
        ->assertExitCode(2);

    $files = collect(Storage::disk('local')->allFiles('service-request-backfills'));
    $verificationPath = $files->first(fn (string $path): bool => str_ends_with($path, 'verification.json'));
    $segmentPath = $files->first(fn (string $path): bool => str_ends_with($path, 'verification-segments.csv'));

    expect($verificationPath)->not->toBeNull()
        ->and($segmentPath)->not->toBeNull();

    $verification = json_decode((string) Storage::disk('local')->get((string) $verificationPath), true);
    $segmentsCsv = (string) Storage::disk('local')->get((string) $segmentPath);

    expect($verification)->toBeArray()
        ->and(data_get($verification, 'leads_with_issues'))->toBe(1)
        ->and(data_get($verification, 'remaining_eligible_count'))->toBe(0)
        ->and(data_get($verification, 'remaining_review_count'))->toBe(1)
        ->and(data_get($verification, 'review_samples.0.id'))->toBeGreaterThan(0)
        ->and((array) data_get($verification, 'consistency_issue_samples.0.issue_codes'))->toContain('service_request_source_ref_mismatch')
        ->and($segmentsCsv)->toContain('needs_correction')
        ->and($segmentsCsv)->toContain('needs_review');
});
