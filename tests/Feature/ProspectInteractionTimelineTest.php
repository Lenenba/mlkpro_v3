<?php

use App\Models\ProspectInteraction;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Services\CRM\OutgoingEmailLogService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

function prospectInteractionOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('records note and file additions in the prospect interaction timeline payload', function () {
    Storage::fake('public');

    $owner = prospectInteractionOwner();
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => Carbon::parse('2026-04-25 08:00:00'),
        'title' => 'Interaction timeline',
        'contact_name' => 'Timeline Prospect',
        'contact_email' => 'timeline@example.com',
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-25 09:15:00'));

        $this->actingAs($owner)
            ->postJson(route('prospects.notes.store', $lead), [
                'body' => 'Initial discovery call completed.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Note added.');

        Carbon::setTestNow(Carbon::parse('2026-04-25 10:45:00'));

        $this->actingAs($owner)
            ->post(route('prospects.media.store', $lead), [
                'file' => UploadedFile::fake()->create('scope-brief.pdf', 128, 'application/pdf'),
            ], [
                'Accept' => 'application/json',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'File uploaded.');
    } finally {
        Carbon::setTestNow();
    }

    $lead->refresh();

    expect(ProspectInteraction::query()->where('request_id', $lead->id)->count())->toBe(2)
        ->and($lead->last_activity_at?->equalTo(Carbon::parse('2026-04-25 10:45:00')))->toBeTrue();

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.prospect_interactions.0.type', 'document')
        ->assertJsonPath('lead.prospect_interactions.0.attachment.name', 'scope-brief.pdf')
        ->assertJsonPath('lead.prospect_interactions.1.type', 'note')
        ->assertJsonPath('lead.prospect_interactions.1.description', 'Initial discovery call completed.');
});

it('records sales activity as a structured prospect interaction and syncs the next follow-up', function () {
    $owner = prospectInteractionOwner();
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => Carbon::parse('2026-04-25 08:00:00'),
        'title' => 'Sales activity prospect',
        'contact_name' => 'Sales Prospect',
        'contact_email' => 'sales@example.com',
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-25 11:30:00'));

        $this->actingAs($owner)
            ->postJson(route('crm.sales-activities.requests.store', $lead), [
                'quick_action' => 'callback_tomorrow',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Sales activity logged.')
            ->assertJsonPath('interaction.type', 'next_action')
            ->assertJsonPath('interaction.metadata.activity_key', 'sales_next_action_scheduled');
    } finally {
        Carbon::setTestNow();
    }

    $lead->refresh();

    expect($lead->next_follow_up_at)->not->toBeNull()
        ->and($lead->last_activity_at?->equalTo(Carbon::parse('2026-04-25 11:30:00')))->toBeTrue()
        ->and(ProspectInteraction::query()->where('request_id', $lead->id)->latest('id')->value('type'))->toBe('next_action');
});

it('records crm email events in the prospect interaction timeline', function () {
    $owner = prospectInteractionOwner();
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'status_updated_at' => now(),
        'last_activity_at' => Carbon::parse('2026-04-25 08:00:00'),
        'title' => 'Email interaction prospect',
        'contact_name' => 'Email Prospect',
        'contact_email' => 'email@example.com',
    ]);

    app(OutgoingEmailLogService::class)->logSent(
        $owner,
        $lead,
        [
            'email' => 'email@example.com',
            'provider' => 'gmail',
        ],
        'Email sent from CRM'
    );

    $interaction = ProspectInteraction::query()
        ->where('request_id', $lead->id)
        ->latest('id')
        ->first();

    expect($interaction)->not->toBeNull()
        ->and($interaction?->type)->toBe('email')
        ->and(data_get($interaction?->metadata, 'event_key'))->toBe('message_email_sent')
        ->and(data_get($interaction?->metadata, 'email'))->toBe('email@example.com');

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('lead.prospect_interactions.0.type', 'email')
        ->assertJsonPath('lead.prospect_interactions.0.metadata.event_key', 'message_email_sent');
});
