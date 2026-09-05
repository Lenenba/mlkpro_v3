<?php

use App\Jobs\DispatchCampaignRunJob;
use App\Jobs\SendCampaignRecipientJob;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerOptOut;
use App\Models\User;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\Providers\CampaignProviderManager;
use App\Services\Campaigns\Providers\EmailCampaignProvider;
use App\Services\Campaigns\Providers\SmsCampaignProvider;
use App\Services\SmsNotificationService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * @return array{owner: User, customer: Customer, campaign: Campaign, run: CampaignRun, recipient: CampaignRecipient}
 */
function campaignDeliveryFixture(): array
{
    $owner = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'email' => 'delivery@example.com']);
    app(ConsentService::class)->grant($owner, $customer, Campaign::CHANNEL_EMAIL);
    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Delivery safety',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'status' => Campaign::STATUS_RUNNING,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Hello',
        'body_template' => 'A message for our customer.',
    ]);
    $run = CampaignRun::query()->create([
        'user_id' => $owner->id,
        'campaign_id' => $campaign->id,
        'status' => CampaignRun::STATUS_RUNNING,
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $recipient = CampaignRecipient::query()->create([
        'campaign_run_id' => $run->id,
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'destination' => $customer->email,
        'destination_hash' => CampaignRecipient::destinationHash($customer->email),
        'status' => CampaignRecipient::STATUS_QUEUED,
        'queued_at' => now(),
    ]);

    return compact('owner', 'customer', 'campaign', 'run', 'recipient');
}

test('campaign delivery checks current consent after the recipient was queued', function (string $reason) {
    $fixture = campaignDeliveryFixture();
    Http::preventStrayRequests();
    $this->mock(CampaignProviderManager::class)->shouldNotReceive('send');
    if ($reason === 'consent_revoked') {
        CustomerConsent::query()->where('customer_id', $fixture['customer']->id)->update(['status' => CustomerConsent::STATUS_REVOKED]);
    } else {
        CustomerOptOut::query()->create([
            'user_id' => $fixture['owner']->id,
            'channel' => Campaign::CHANNEL_EMAIL,
            'destination_hash' => $fixture['recipient']->destination_hash,
            'opted_out_at' => now(),
        ]);
    }

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_SKIPPED, 'failure_reason' => $reason]);
    $this->assertDatabaseHas('campaign_events', ['campaign_recipient_id' => $fixture['recipient']->id, 'event_type' => CampaignEvent::EVENT_SKIPPED]);
})->with(['consent revoked' => 'consent_revoked', 'destination opted out' => 'opted_out']);

test('campaign delivery stops when its run campaign or channel was canceled', function (string $changed) {
    $fixture = campaignDeliveryFixture();
    $this->mock(CampaignProviderManager::class)->shouldNotReceive('send');
    match ($changed) {
        'run' => $fixture['run']->update(['status' => CampaignRun::STATUS_CANCELED]),
        'campaign' => $fixture['campaign']->update(['status' => Campaign::STATUS_CANCELED]),
        'channel' => $fixture['campaign']->channels()->update(['is_enabled' => false]),
    };

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_SKIPPED]);
    $this->assertDatabaseMissing('campaign_events', ['campaign_recipient_id' => $fixture['recipient']->id, 'event_type' => CampaignEvent::EVENT_SENT]);
    if ($changed === 'campaign') {
        expect($fixture['campaign']->fresh()->status)->toBe(Campaign::STATUS_CANCELED);
    } elseif ($changed === 'run') {
        expect($fixture['run']->fresh()->status)->toBe(CampaignRun::STATUS_CANCELED);
    }
})->with(['run', 'campaign', 'channel']);

test('campaign delivery respects a prospect marked do not contact after preparation', function () {
    $fixture = campaignDeliveryFixture();
    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $fixture['campaign']->id,
        'user_id' => $fixture['owner']->id,
        'source_type' => CampaignProspect::SOURCE_MANUAL,
        'batch_number' => 1,
        'status' => CampaignProspectBatch::STATUS_APPROVED,
    ]);
    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $fixture['campaign']->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $fixture['owner']->id,
        'source_type' => CampaignProspect::SOURCE_MANUAL,
        'status' => CampaignProspect::STATUS_DO_NOT_CONTACT,
        'do_not_contact' => true,
    ]);
    $fixture['recipient']->update(['metadata' => ['prospect_id' => $prospect->id]]);
    $this->mock(CampaignProviderManager::class)->shouldNotReceive('send');

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_SKIPPED, 'failure_reason' => 'do_not_contact']);
});

test('a known rejection creates an independent fallback and its interrupted dispatch can resume', function () {
    $fixture = campaignDeliveryFixture();
    $fixture['customer']->update(['phone' => '+15145550123']);
    app(ConsentService::class)->grant($fixture['owner'], $fixture['customer'], Campaign::CHANNEL_SMS);
    app(\App\Services\Campaigns\MarketingSettingsService::class)->update($fixture['owner'], ['channels' => ['quiet_hours' => ['start' => '00:00', 'end' => '00:00']]]);
    $fixture['campaign']->update(['settings' => ['channel_fallback' => ['enabled' => true, 'map' => ['EMAIL' => ['SMS']]]]]);
    $fixture['campaign']->channels()->create(['channel' => Campaign::CHANNEL_SMS, 'is_enabled' => true, 'body_template' => 'Hello']);
    Queue::fake([SendCampaignRecipientJob::class]);
    $this->mock(CampaignProviderManager::class)->shouldReceive('send')->once()->andReturn(['ok' => false, 'provider' => 'fake', 'reason' => 'recipient_rejected', 'delivery_outcome' => 'rejected']);

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $fallback = $fixture['run']->recipients()->where('channel', Campaign::CHANNEL_SMS)->sole();
    expect(data_get($fallback->metadata, 'delivery_attempt'))->toBeNull();
    Queue::assertPushed(SendCampaignRecipientJob::class, fn (SendCampaignRecipientJob $job) => $job->campaignRecipientId === $fallback->id);

    Queue::fake([SendCampaignRecipientJob::class]);
    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    Queue::assertPushed(SendCampaignRecipientJob::class, 1);
    expect($fixture['run']->recipients()->count())->toBe(2);
});

test('overlapping campaign workers submit a recipient once', function () {
    $fixture = campaignDeliveryFixture();
    $recipient = $fixture['recipient'];
    $this->mock(CampaignProviderManager::class)->shouldReceive('send')->once()->andReturnUsing(function () use ($recipient): array {
        app()->call([new SendCampaignRecipientJob($recipient->id), 'handle']);

        return ['ok' => true, 'provider' => 'fake', 'provider_message_id' => 'message-once'];
    });

    app()->call([new SendCampaignRecipientJob($recipient->id), 'handle']);
    app()->call([new SendCampaignRecipientJob($recipient->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => CampaignRecipient::STATUS_SENT, 'provider_message_id' => 'message-once']);
    expect($recipient->events()->where('event_type', CampaignEvent::EVENT_SENT)->count())->toBe(1);
});

test('campaign retry completes tracking without resending after provider acceptance', function () {
    $fixture = campaignDeliveryFixture();
    $recipient = $fixture['recipient'];
    $this->mock(CampaignProviderManager::class)->shouldReceive('send')->once()->andReturn(['ok' => true, 'provider' => 'fake', 'provider_message_id' => 'accepted-message']);
    $failTracking = true;
    CampaignEvent::creating(function (CampaignEvent $event) use (&$failTracking): void {
        if ($failTracking && $event->event_type === CampaignEvent::EVENT_SENT) {
            $failTracking = false;
            throw new RuntimeException('Local tracking unavailable');
        }
    });

    expect(fn () => app()->call([new SendCampaignRecipientJob($recipient->id), 'handle']))->toThrow(RuntimeException::class, 'Local tracking unavailable');

    $recipient->refresh();
    expect(data_get($recipient->metadata, 'delivery_attempt.state'))->toBe('accepted');
    $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => CampaignRecipient::STATUS_QUEUED, 'provider_message_id' => 'accepted-message']);
    expect($recipient->events()->count())->toBe(0);

    app()->call([new SendCampaignRecipientJob($recipient->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => CampaignRecipient::STATUS_SENT]);
    expect($recipient->events()->where('event_type', CampaignEvent::EVENT_SENT)->count())->toBe(1);
});

test('an unknown campaign provider result blocks retry and fallback and fails the run visibly', function () {
    $fixture = campaignDeliveryFixture();
    Queue::fake();
    Exceptions::fake();
    $fixture['campaign']->update(['settings' => ['channel_fallback' => ['enabled' => true, 'map' => ['EMAIL' => ['SMS']]]]]);
    $this->mock(CampaignProviderManager::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('Provider connection lost'));

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);
    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_FAILED, 'failure_reason' => 'provider_result_unknown']);
    $this->assertDatabaseHas('campaign_runs', ['id' => $fixture['run']->id, 'status' => CampaignRun::STATUS_FAILED, 'error_message' => 'provider_result_unknown', 'completed_at' => null]);
    expect(data_get($fixture['run']->fresh()->summary, 'delivery_unknown'))->toBe(1);
    expect(data_get($fixture['recipient']->fresh()->metadata, 'delivery_attempt.state'))->toBe('unknown');
    Queue::assertNothingPushed();
    Exceptions::assertReported(RuntimeException::class);
});

test('a crashed campaign submission is marked unknown without another provider call', function () {
    $this->freezeTime();
    $fixture = campaignDeliveryFixture();
    $fixture['recipient']->update(['metadata' => ['delivery_attempt' => [
        'id' => 'interrupted-attempt',
        'state' => 'submitting',
        'started_at' => now()->subMinutes(5)->toIso8601String(),
    ]]]);
    $this->mock(CampaignProviderManager::class)->shouldNotReceive('send');

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'failure_reason' => 'provider_result_unknown']);
    expect(data_get($fixture['recipient']->fresh()->metadata, 'delivery_attempt.id'))->toBe('interrupted-attempt');
});

test('a recent interrupted submission releases its queue retry until the uncertainty deadline', function () {
    $this->freezeTime();
    $fixture = campaignDeliveryFixture();
    $fixture['recipient']->update(['metadata' => ['delivery_attempt' => [
        'id' => 'recent-attempt', 'state' => 'submitting', 'started_at' => now()->toIso8601String(),
    ]]]);
    $this->mock(CampaignProviderManager::class)->shouldNotReceive('send');
    $job = (new SendCampaignRecipientJob($fixture['recipient']->id))->withFakeQueueInteractions();

    app()->call([$job, 'handle']);

    $job->assertReleased(120);
    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_QUEUED]);

    $this->travel(121)->seconds();
    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'failure_reason' => 'provider_result_unknown']);
});

test('provider acceptance followed by result persistence failure schedules safe reconciliation', function () {
    $this->freezeTime();
    $fixture = campaignDeliveryFixture();
    $this->mock(CampaignProviderManager::class)->shouldReceive('send')->once()->andReturn(['ok' => true, 'provider' => 'fake', 'provider_message_id' => 'unrecorded-acceptance']);
    $failResultSave = true;
    CampaignRecipient::updating(function (CampaignRecipient $recipient) use (&$failResultSave): void {
        if ($failResultSave && data_get($recipient->metadata, 'delivery_attempt.state') === 'accepted') {
            $failResultSave = false;
            throw new RuntimeException('Result persistence unavailable');
        }
    });

    expect(fn () => app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']))->toThrow(RuntimeException::class, 'Result persistence unavailable');

    $retry = (new SendCampaignRecipientJob($fixture['recipient']->id))->withFakeQueueInteractions();
    app()->call([$retry, 'handle']);
    $retry->assertReleased(120);

    $this->travel(121)->seconds();
    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_FAILED, 'failure_reason' => 'provider_result_unknown']);
    $this->assertDatabaseHas('campaign_runs', ['id' => $fixture['run']->id, 'status' => CampaignRun::STATUS_FAILED]);
});

test('an exhausted campaign job records uncertainty instead of leaving an orphaned queued recipient', function () {
    $fixture = campaignDeliveryFixture();
    $fixture['recipient']->update(['metadata' => ['delivery_attempt' => [
        'id' => 'exhausted-attempt', 'state' => 'submitting', 'started_at' => now()->toIso8601String(),
    ]]]);
    $this->mock(CampaignProviderManager::class)->shouldNotReceive('send');

    (new SendCampaignRecipientJob($fixture['recipient']->id))->failed(new RuntimeException('Retries exhausted'));

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_FAILED, 'failure_reason' => 'provider_result_unknown']);
    $this->assertDatabaseHas('campaign_runs', ['id' => $fixture['run']->id, 'status' => CampaignRun::STATUS_FAILED]);
});

test('sms provider distinguishes ambiguous transport failures from definite rejection', function (array $transportResult, string $outcome) {
    $fixture = campaignDeliveryFixture();
    $message = new CampaignMessage(['body_rendered' => 'Hello']);
    $this->mock(SmsNotificationService::class)->shouldReceive('sendWithResult')->once()->andReturn($transportResult);

    $result = app(SmsCampaignProvider::class)->send($fixture['recipient'], $message);

    expect($result['delivery_outcome'])->toBe($outcome);
})->with([
    'connection lost' => [['ok' => false, 'reason' => 'http_exception'], 'unknown'],
    'server failure' => [['ok' => false, 'reason' => 'twilio_error', 'status' => 500], 'unknown'],
    'recipient rejected' => [['ok' => false, 'reason' => 'twilio_error', 'status' => 400], 'rejected'],
]);

test('mail provider reports an exception as uncertain delivery', function () {
    $fixture = campaignDeliveryFixture();
    Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('SMTP acknowledgement lost'));

    $result = app(EmailCampaignProvider::class)->send($fixture['recipient'], new CampaignMessage(['body_rendered' => 'Hello']));

    expect($result['delivery_outcome'])->toBe('unknown');
});

test('campaign preparation failure rolls back the claim and can retry safely', function () {
    $fixture = campaignDeliveryFixture();
    $failPreparation = true;
    CampaignMessage::creating(function () use (&$failPreparation): void {
        if ($failPreparation) {
            $failPreparation = false;
            throw new RuntimeException('Message preparation interrupted');
        }
    });
    $this->mock(CampaignProviderManager::class)->shouldReceive('send')->once()->andReturn(['ok' => true, 'provider' => 'fake']);

    expect(fn () => app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']))->toThrow(RuntimeException::class, 'Message preparation interrupted');

    expect(data_get($fixture['recipient']->fresh()->metadata, 'delivery_attempt'))->toBeNull();
    expect($fixture['recipient']->message()->exists())->toBeFalse();

    app()->call([new SendCampaignRecipientJob($fixture['recipient']->id), 'handle']);

    $this->assertDatabaseHas('campaign_recipients', ['id' => $fixture['recipient']->id, 'status' => CampaignRecipient::STATUS_SENT]);
});

test('campaign audience retry recovers a historical partial preparation and freezes the completed snapshot', function () {
    $fixture = campaignDeliveryFixture();
    Queue::fake([SendCampaignRecipientJob::class]);
    app(\App\Services\Campaigns\MarketingSettingsService::class)->update($fixture['owner'], ['channels' => ['quiet_hours' => ['start' => '00:00', 'end' => '00:00']]]);
    $additional = Customer::factory()->count(2)->create(['user_id' => $fixture['owner']->id]);
    foreach ($additional as $customer) {
        app(ConsentService::class)->grant($fixture['owner'], $customer, Campaign::CHANNEL_EMAIL);
    }

    app()->call([new DispatchCampaignRunJob($fixture['run']->id), 'handle']);

    expect($fixture['run']->recipients()->count())->toBe(3);
    expect(data_get($fixture['run']->fresh()->audience_snapshot, 'prepared_at'))->not->toBeNull();
    Queue::assertPushed(SendCampaignRecipientJob::class, 3);

    $later = Customer::factory()->create(['user_id' => $fixture['owner']->id]);
    app(ConsentService::class)->grant($fixture['owner'], $later, Campaign::CHANNEL_EMAIL);
    app()->call([new DispatchCampaignRunJob($fixture['run']->id), 'handle']);

    expect($fixture['run']->recipients()->count())->toBe(3);
});

test('campaign audience preparation rolls back all new recipients if interrupted', function () {
    $fixture = campaignDeliveryFixture();
    $fixture['recipient']->delete();
    Queue::fake([SendCampaignRecipientJob::class]);
    app(\App\Services\Campaigns\MarketingSettingsService::class)->update($fixture['owner'], ['channels' => ['quiet_hours' => ['start' => '00:00', 'end' => '00:00']]]);
    $additional = Customer::factory()->create(['user_id' => $fixture['owner']->id]);
    app(ConsentService::class)->grant($fixture['owner'], $additional, Campaign::CHANNEL_EMAIL);
    $createdCount = 0;
    CampaignRecipient::created(function () use (&$createdCount): void {
        $createdCount++;
        if ($createdCount === 2) {
            throw new RuntimeException('Audience preparation interrupted');
        }
    });

    expect(fn () => app()->call([new DispatchCampaignRunJob($fixture['run']->id), 'handle']))->toThrow(RuntimeException::class, 'Audience preparation interrupted');

    expect($fixture['run']->recipients()->count())->toBe(0);
    expect(data_get($fixture['run']->fresh()->audience_snapshot, 'prepared_at'))->toBeNull();
    Queue::assertNothingPushed();

    app()->call([new DispatchCampaignRunJob($fixture['run']->id), 'handle']);

    expect($fixture['run']->recipients()->count())->toBe(2);
    Queue::assertPushed(SendCampaignRecipientJob::class, 2);
});
