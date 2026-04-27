<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\LeadNote;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('runs the prospect migration dry-run without writing changes and reports real eligible and ambiguous customers', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $otherOwner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $realCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Real Customer Inc.',
        'email' => 'real@example.com',
    ]);

    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $realCustomer->id,
        'job_title' => 'Accepted quote',
        'status' => 'accepted',
        'accepted_at' => now(),
        'subtotal' => 1200,
        'total' => 1200,
        'currency_code' => 'CAD',
    ]);

    $eligibleRequestCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Request-only Prospect',
        'email' => 'request-only@example.com',
    ]);

    LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $eligibleRequestCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Request-only prospect',
        'contact_name' => 'Request Prospect',
        'contact_email' => 'request-only@example.com',
    ]);

    $eligibleQuoteCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Quote-only Prospect',
        'email' => 'quote-only@example.com',
    ]);

    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $eligibleQuoteCustomer->id,
        'job_title' => 'Open quote',
        'status' => 'sent',
        'subtotal' => 800,
        'total' => 800,
        'currency_code' => 'CAD',
    ]);

    $ambiguousCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Needs qualification',
        'email' => 'ambiguous@example.com',
    ]);

    Customer::factory()->create([
        'user_id' => $otherOwner->id,
        'company_name' => 'Other account customer',
        'email' => 'other@example.com',
    ]);

    $before = [
        'customers' => Customer::query()->count(),
        'requests' => LeadRequest::query()->count(),
        'quotes' => Quote::query()->count(),
        'activity_logs' => ActivityLog::query()->count(),
    ];

    $this->artisan('prospects:migration-dry-run', [
        '--account_id' => $owner->id,
    ])
        ->expectsOutputToContain('Prospect migration dry run')
        ->expectsOutputToContain('Scanned customers: 4')
        ->expectsOutputToContain('Real customers: 1')
        ->expectsOutputToContain('Eligible prospects: 2')
        ->expectsOutputToContain('Ambiguous / a qualifier: 1')
        ->expectsOutputToContain('real.accepted_quotes: 1')
        ->expectsOutputToContain('eligible.requests_only: 1')
        ->expectsOutputToContain('eligible.open_quotes_only: 1')
        ->expectsOutputToContain('ambiguous.no_presales_activity: 1')
        ->expectsOutputToContain('Needs qualification')
        ->assertExitCode(0);

    expect(Customer::query()->count())->toBe($before['customers'])
        ->and(LeadRequest::query()->count())->toBe($before['requests'])
        ->and(Quote::query()->count())->toBe($before['quotes'])
        ->and(ActivityLog::query()->count())->toBe($before['activity_logs']);
});

it('runs the real prospect migration with a journal and preserves request and quote history', function () {
    Storage::fake('local');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $legacyRequestCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Legacy Request Prospect',
        'email' => 'legacy-request@example.com',
        'phone' => '+1 555 0101',
    ]);

    $legacyRequestCustomer->timestamps = false;
    $legacyRequestCustomer->forceFill([
        'created_at' => now()->subDays(45),
        'updated_at' => now()->subDays(14),
    ])->save();
    $legacyRequestCustomer->timestamps = true;

    $existingLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $legacyRequestCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now()->subDays(30),
        'last_activity_at' => now()->subDays(18),
        'title' => 'Legacy contact request',
        'contact_name' => 'Legacy Prospect',
        'contact_email' => 'legacy-request@example.com',
        'contact_phone' => '+1 555 0101',
        'meta' => [
            'company_name' => 'Legacy Request Prospect',
        ],
    ]);

    $existingLead->timestamps = false;
    $existingLead->forceFill([
        'created_at' => now()->subDays(42),
        'updated_at' => now()->subDays(16),
    ])->save();
    $existingLead->timestamps = true;

    LeadNote::query()->create([
        'request_id' => $existingLead->id,
        'user_id' => $owner->id,
        'body' => 'Keep this timeline note.',
    ]);

    $legacyQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $legacyRequestCustomer->id,
        'job_title' => 'Legacy open quote',
        'status' => 'sent',
        'subtotal' => 1400,
        'total' => 1400,
        'currency_code' => 'CAD',
    ]);

    $legacyQuote->timestamps = false;
    $legacyQuote->forceFill([
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(15),
        'last_sent_at' => now()->subDays(15),
    ])->save();
    $legacyQuote->timestamps = true;

    $quoteOnlyCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Quote Only Prospect',
        'first_name' => 'Quentin',
        'last_name' => 'Prospect',
        'email' => 'quote-only@example.com',
        'phone' => '+1 555 0102',
    ]);

    $quoteOnlyCustomer->timestamps = false;
    $quoteOnlyCustomer->forceFill([
        'created_at' => now()->subDays(28),
        'updated_at' => now()->subDays(7),
    ])->save();
    $quoteOnlyCustomer->timestamps = true;

    $quoteOnlyQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quoteOnlyCustomer->id,
        'job_title' => 'Quote only open estimate',
        'status' => 'sent',
        'subtotal' => 900,
        'total' => 900,
        'currency_code' => 'CAD',
    ]);

    $quoteOnlyQuote->timestamps = false;
    $quoteOnlyQuote->forceFill([
        'created_at' => now()->subDays(27),
        'updated_at' => now()->subDays(6),
        'last_sent_at' => now()->subDays(6),
    ])->save();
    $quoteOnlyQuote->timestamps = true;

    $realCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Real Customer Inc.',
        'email' => 'real-migrate@example.com',
    ]);

    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $realCustomer->id,
        'job_title' => 'Accepted real quote',
        'status' => 'accepted',
        'accepted_at' => now(),
        'subtotal' => 1800,
        'total' => 1800,
        'currency_code' => 'CAD',
    ]);

    $beforeLeadCount = LeadRequest::query()->count();

    $this->artisan('prospects:migration-run', [
        '--account_id' => $owner->id,
        '--force' => true,
    ])
        ->expectsOutputToContain('Prospect migration completed')
        ->expectsOutputToContain('Scanned customers: 3')
        ->expectsOutputToContain('Eligible customers: 2')
        ->expectsOutputToContain('Migrated customers: 2')
        ->expectsOutputToContain('Created prospects: 1')
        ->expectsOutputToContain('Existing requests detached: 1')
        ->expectsOutputToContain('Rewired quotes: 2')
        ->expectsOutputToContain('Failed customers: 0')
        ->assertExitCode(0);

    $existingLead->refresh();
    $legacyQuote->refresh();
    $quoteOnlyQuote->refresh();

    $createdLead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->whereNull('customer_id')
        ->where('contact_email', 'quote-only@example.com')
        ->where('channel', 'migration')
        ->first();

    expect($createdLead)->not->toBeNull();

    expect(LeadRequest::query()->count())->toBe($beforeLeadCount + 1)
        ->and($existingLead->customer_id)->toBeNull()
        ->and($existingLead->created_at?->toDateString())->toBe(now()->subDays(42)->toDateString())
        ->and($existingLead->updated_at?->toDateString())->toBe(now()->subDays(16)->toDateString())
        ->and(data_get($existingLead->meta, 'migration.legacy_customer_id'))->toBe($legacyRequestCustomer->id)
        ->and(data_get($existingLead->meta, 'migration.primary_prospect_id'))->toBe($existingLead->id)
        ->and($legacyQuote->customer_id)->toBeNull()
        ->and($legacyQuote->request_id)->toBe($existingLead->id)
        ->and($legacyQuote->prospect_id)->toBe($existingLead->id)
        ->and($legacyQuote->updated_at?->toDateString())->toBe(now()->subDays(15)->toDateString())
        ->and(LeadNote::query()->where('request_id', $existingLead->id)->where('body', 'Keep this timeline note.')->exists())->toBeTrue()
        ->and($createdLead?->status)->toBe(LeadRequest::STATUS_QUOTE_SENT)
        ->and($createdLead?->title)->toBe('Quote only open estimate')
        ->and($createdLead?->customer_id)->toBeNull()
        ->and($createdLead?->created_at?->toDateString())->toBe(now()->subDays(28)->toDateString())
        ->and(data_get($createdLead?->meta, 'migration.legacy_customer_id'))->toBe($quoteOnlyCustomer->id)
        ->and(data_get($createdLead?->meta, 'migration.request_role'))->toBe('created_from_quote_only_customer')
        ->and($quoteOnlyQuote->customer_id)->toBeNull()
        ->and($quoteOnlyQuote->request_id)->toBe($createdLead?->id)
        ->and($quoteOnlyQuote->prospect_id)->toBe($createdLead?->id);

    expect(ActivityLog::query()
        ->where('subject_type', $legacyRequestCustomer->getMorphClass())
        ->where('subject_id', $legacyRequestCustomer->id)
        ->where('action', 'prospect_customer_reclassified')
        ->exists())->toBeTrue()
        ->and(ActivityLog::query()
            ->where('subject_type', $existingLead->getMorphClass())
            ->where('subject_id', $existingLead->id)
            ->where('action', 'prospect_customer_migration_linked')
            ->exists())->toBeTrue()
        ->and(ActivityLog::query()
            ->where('subject_type', $createdLead?->getMorphClass())
            ->where('subject_id', $createdLead?->id)
            ->where('action', 'prospect_customer_migration_linked')
            ->exists())->toBeTrue();

    $files = collect(Storage::disk('local')->allFiles('prospect-migrations'));
    $summaryPath = $files->first(fn (string $path): bool => str_ends_with($path, 'summary.json'));
    $mappingPath = $files->first(fn (string $path): bool => str_ends_with($path, 'mappings.csv'));

    expect($summaryPath)->not->toBeNull()
        ->and($mappingPath)->not->toBeNull();

    $summary = json_decode((string) Storage::disk('local')->get((string) $summaryPath), true);
    $mappingCsv = (string) Storage::disk('local')->get((string) $mappingPath);

    expect($summary)->toBeArray()
        ->and(data_get($summary, 'migrated_count'))->toBe(2)
        ->and(data_get($summary, 'created_prospects_count'))->toBe(1)
        ->and(data_get($summary, 'rewired_quotes_count'))->toBe(2)
        ->and($mappingCsv)->toContain('legacy_customer_id')
        ->and($mappingCsv)->toContain((string) $legacyRequestCustomer->id)
        ->and($mappingCsv)->toContain((string) $quoteOnlyCustomer->id);
});

it('verifies a migration batch, excludes already migrated legacy customers, and surfaces remaining qualification work', function () {
    Storage::fake('local');

    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ]);

    $legacyCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Legacy Verification Prospect',
        'email' => 'legacy-verify@example.com',
        'phone' => '+1 555 0103',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $legacyCustomer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now()->subDays(12),
        'last_activity_at' => now()->subDays(10),
        'title' => 'Verification lead',
        'contact_name' => 'Legacy Verify',
        'contact_email' => 'legacy-verify@example.com',
        'contact_phone' => '+1 555 0103',
        'meta' => [
            'company_name' => 'Legacy Verification Prospect',
        ],
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $legacyCustomer->id,
        'job_title' => 'Verification quote',
        'status' => 'sent',
        'subtotal' => 1100,
        'total' => 1100,
        'currency_code' => 'CAD',
    ]);

    $ambiguousCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Needs Qualification',
        'email' => 'needs-qualification@example.com',
    ]);

    $this->artisan('prospects:migration-run', [
        '--account_id' => $owner->id,
        '--force' => true,
    ])->assertExitCode(0);

    $quote->refresh();
    $lead->refresh();

    $quote->timestamps = false;
    $quote->forceFill([
        'customer_id' => $legacyCustomer->id,
    ])->save();
    $quote->timestamps = true;

    $this->artisan('prospects:migration-verify', [
        '--account_id' => $owner->id,
    ])
        ->expectsOutputToContain('Prospect migration verification')
        ->expectsOutputToContain('Migrated customers checked: 1')
        ->expectsOutputToContain('Verified customers: 0')
        ->expectsOutputToContain('Customers with issues: 1')
        ->expectsOutputToContain('Remaining eligible customers: 0')
        ->expectsOutputToContain('Remaining ambiguous / a qualifier: 1')
        ->expectsOutputToContain('quote_still_linked_to_legacy_customer')
        ->expectsOutputToContain('Needs Qualification')
        ->assertExitCode(2);

    $files = collect(Storage::disk('local')->allFiles('prospect-migrations'));
    $verificationPath = $files->first(fn (string $path): bool => str_ends_with($path, 'verification.json'));
    $segmentPath = $files->first(fn (string $path): bool => str_ends_with($path, 'verification-segments.csv'));

    expect($verificationPath)->not->toBeNull()
        ->and($segmentPath)->not->toBeNull();

    $verification = json_decode((string) Storage::disk('local')->get((string) $verificationPath), true);
    $segmentsCsv = (string) Storage::disk('local')->get((string) $segmentPath);

    expect($verification)->toBeArray()
        ->and(data_get($verification, 'customers_with_issues'))->toBe(1)
        ->and(data_get($verification, 'remaining_eligible_count'))->toBe(0)
        ->and(data_get($verification, 'remaining_ambiguous_count'))->toBe(1)
        ->and(data_get($verification, 'qualification_samples.0.id'))->toBe($ambiguousCustomer->id)
        ->and(data_get($verification, 'consistency_issue_samples.0.legacy_customer_id'))->toBe($legacyCustomer->id)
        ->and((array) data_get($verification, 'consistency_issue_samples.0.issue_codes'))->toContain('quote_still_linked_to_legacy_customer')
        ->and($segmentsCsv)->toContain('needs_correction')
        ->and($segmentsCsv)->toContain('needs_qualification')
        ->and($segmentsCsv)->toContain((string) $legacyCustomer->id)
        ->and($segmentsCsv)->toContain((string) $ambiguousCustomer->id);
});
