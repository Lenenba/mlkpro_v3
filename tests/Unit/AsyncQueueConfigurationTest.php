<?php

use App\Jobs\AnalyzePlanScanJob;
use App\Jobs\DispatchCampaignRunJob;
use App\Jobs\GenerateSocialPostCandidateJob;
use App\Jobs\GenerateWorkTasks;
use App\Jobs\ProvisionDemoWorkspaceJob;
use App\Jobs\PublishSocialPostTargetJob;
use App\Jobs\ReconcileDeliveryReportsJob;
use App\Jobs\RetryLeadQuoteEmailJob;
use App\Jobs\SendCampaignRecipientJob;
use App\Models\Request as LeadRequest;
use App\Notifications\ActionEmailNotification;
use App\Notifications\CampaignInAppNotification;
use App\Notifications\LeadFollowUpNotification;
use App\Support\QueueWorkload;
use Illuminate\Queue\Middleware\WithoutOverlapping;

uses(Tests\TestCase::class);

test('async workloads apply explicit queue names to jobs and notifications', function () {
    config()->set('async.workloads.notifications.queue', 'notifications-test');
    config()->set('async.workloads.leads.queue', 'leads-test');
    config()->set('async.workloads.works.queue', 'works-test');
    config()->set('async.workloads.demos.queue', 'demos-test');
    config()->set('async.workloads.plan_scans.queue', 'plan-scans-test');
    config()->set('async.workloads.campaigns_dispatch.queue', 'campaigns-dispatch-test');
    config()->set('async.workloads.campaigns_send.queue', 'campaigns-send-test');
    config()->set('async.workloads.campaigns_maintenance.queue', 'campaigns-maintenance-test');
    config()->set('async.workloads.social_automation.queue', 'social-automation-test');
    config()->set('async.workloads.social_publish.queue', 'social-publish-test');

    expect((new ActionEmailNotification('Phase 6'))->queue)->toBe('notifications-test')
        ->and((new CampaignInAppNotification(['title' => 'Campaign']))->queue)->toBe('notifications-test')
        ->and((new LeadFollowUpNotification(new LeadRequest))->queue)->toBe('notifications-test')
        ->and((new RetryLeadQuoteEmailJob(10, 20))->queue)->toBe('leads-test')
        ->and((new GenerateWorkTasks(99))->queue)->toBe('works-test')
        ->and((new ProvisionDemoWorkspaceJob(1, 2))->queue)->toBe('demos-test')
        ->and((new AnalyzePlanScanJob(1, 2))->queue)->toBe('plan-scans-test')
        ->and((new DispatchCampaignRunJob(77))->queue)->toBe('campaigns-dispatch-test')
        ->and((new SendCampaignRecipientJob(77))->queue)->toBe('campaigns-send-test')
        ->and((new ReconcileDeliveryReportsJob)->queue)->toBe('campaigns-maintenance-test')
        ->and((new GenerateSocialPostCandidateJob(77))->queue)->toBe('social-automation-test')
        ->and((new PublishSocialPostTargetJob(77))->queue)->toBe('social-publish-test');
});

test('async workloads expose configured backoff policies', function () {
    config()->set('async.workloads.notifications.backoff', [5, 25, 125]);
    config()->set('async.workloads.leads.backoff', [15, 60, 300]);
    config()->set('async.workloads.works.backoff', [20, 80, 320]);
    config()->set('async.workloads.plan_scans.backoff', [45]);
    config()->set('async.workloads.campaigns_dispatch.backoff', [12, 48, 192]);
    config()->set('async.workloads.campaigns_send.backoff', [10, 40, 160]);
    config()->set('async.workloads.campaigns_maintenance.backoff', [90, 360]);
    config()->set('async.workloads.social_automation.backoff', [35, 140, 560]);
    config()->set('async.workloads.social_publish.backoff', [25, 100, 400]);

    expect((new ActionEmailNotification('Phase 6'))->backoff())->toBe([5, 25, 125])
        ->and((new LeadFollowUpNotification(new LeadRequest))->backoff())->toBe([5, 25, 125])
        ->and((new RetryLeadQuoteEmailJob(10, 20))->backoff())->toBe([15, 60, 300])
        ->and((new GenerateWorkTasks(99))->backoff())->toBe([20, 80, 320])
        ->and((new AnalyzePlanScanJob(1, 2))->backoff())->toBe([45])
        ->and((new DispatchCampaignRunJob(77))->backoff())->toBe([12, 48, 192])
        ->and((new SendCampaignRecipientJob(77))->backoff())->toBe([10, 40, 160])
        ->and((new ReconcileDeliveryReportsJob)->backoff())->toBe([90, 360])
        ->and((new GenerateSocialPostCandidateJob(77))->backoff())->toBe([35, 140, 560])
        ->and((new PublishSocialPostTargetJob(77))->backoff())->toBe([25, 100, 400]);
});

test('demo provisioning serializes work per workspace and has a bounded runtime', function () {
    $job = new ProvisionDemoWorkspaceJob(41, 2, true);
    $middleware = $job->middleware();

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(900)
        ->and(QueueWorkload::timeout('demos'))->toBe(900)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('demo-workspace:41')
        ->and($middleware[0]->releaseAfter)->toBe(30)
        ->and($middleware[0]->expiresAfter)->toBe(960)
        ->and($middleware[0]->shareKey)->toBeTrue();
});
