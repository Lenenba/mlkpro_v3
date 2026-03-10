<?php

use App\Jobs\GenerateWorkTasks;
use App\Jobs\RetryLeadQuoteEmailJob;
use App\Jobs\SendCampaignRecipientJob;
use App\Notifications\ActionEmailNotification;
use App\Notifications\CampaignInAppNotification;

uses(Tests\TestCase::class);

test('async workloads apply explicit queue names to jobs and notifications', function () {
    config()->set('async.workloads.notifications.queue', 'notifications-test');
    config()->set('async.workloads.leads.queue', 'leads-test');
    config()->set('async.workloads.works.queue', 'works-test');
    config()->set('async.workloads.campaigns_send.queue', 'campaigns-send-test');

    expect((new ActionEmailNotification('Phase 6'))->queue)->toBe('notifications-test')
        ->and((new CampaignInAppNotification(['title' => 'Campaign']))->queue)->toBe('notifications-test')
        ->and((new RetryLeadQuoteEmailJob(10, 20))->queue)->toBe('leads-test')
        ->and((new GenerateWorkTasks(99))->queue)->toBe('works-test')
        ->and((new SendCampaignRecipientJob(77))->queue)->toBe('campaigns-send-test');
});

test('async workloads expose configured backoff policies', function () {
    config()->set('async.workloads.notifications.backoff', [5, 25, 125]);
    config()->set('async.workloads.leads.backoff', [15, 60, 300]);
    config()->set('async.workloads.works.backoff', [20, 80, 320]);
    config()->set('async.workloads.campaigns_send.backoff', [10, 40, 160]);

    expect((new ActionEmailNotification('Phase 6'))->backoff())->toBe([5, 25, 125])
        ->and((new RetryLeadQuoteEmailJob(10, 20))->backoff())->toBe([15, 60, 300])
        ->and((new GenerateWorkTasks(99))->backoff())->toBe([20, 80, 320])
        ->and((new SendCampaignRecipientJob(77))->backoff())->toBe([10, 40, 160]);
});
