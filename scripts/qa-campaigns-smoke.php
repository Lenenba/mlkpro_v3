<?php

declare(strict_types=1);

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignRunController;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Campaigns\CampaignService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

require __DIR__ . '/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$out = static function (string $message): void {
    echo '[' . now()->format('H:i:s') . "] {$message}\n";
};

try {
    $owner = User::query()
        ->where('company_type', 'products')
        ->orderBy('id')
        ->first();

    if (!$owner) {
        throw new RuntimeException('No products owner found.');
    }

    $product = Product::query()
        ->where('user_id', $owner->id)
        ->orderBy('id')
        ->first();

    if (!$product) {
        throw new RuntimeException("No product found for owner #{$owner->id}.");
    }

    $customer = Customer::query()
        ->where('user_id', $owner->id)
        ->whereNotNull('email')
        ->orderBy('id')
        ->first();

    if (!$customer) {
        throw new RuntimeException("No customer with email found for owner #{$owner->id}.");
    }

    $timestamp = now()->format('Ymd_His');
    $payload = [
        'name' => "QA Campaign {$timestamp}",
        'type' => Campaign::TYPE_PROMOTION,
        'product_ids' => [$product->id],
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'scheduled_at' => null,
        'locale' => 'en',
        'cta_url' => 'https://example.com/offer',
        'audience_segment_id' => null,
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Special offer for {firstName}',
                'title_template' => null,
                'body_template' => 'Hello {firstName}, use {promoCode} and click {ctaUrl}',
            ],
        ],
        'audience' => [
            'smart_filters' => null,
            'exclusion_filters' => null,
            'manual_customer_ids' => [$customer->id],
            'manual_contacts' => [],
            'estimated_counts' => null,
        ],
        'settings' => [
            'promo_code' => 'QA10',
            'promo_percent' => 10,
            'promo_end_date' => now()->addDays(10)->toDateString(),
        ],
    ];

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);
    $campaign = $campaignService->saveCampaign($owner, $owner, $payload);
    $out("Created campaign #{$campaign->id}");

    /** @var CampaignController $campaignController */
    $campaignController = app(CampaignController::class);
    /** @var CampaignRunController $runController */
    $runController = app(CampaignRunController::class);

    $makeRequest = static function (array $input = []) use ($owner): Request {
        $request = Request::create('/', 'POST', $input);
        $request->setUserResolver(static fn () => $owner);
        return $request;
    };

    $estimateResponse = $runController->estimate($makeRequest(), $campaign);
    $estimateData = json_decode((string) $estimateResponse->getContent(), true);
    $out('Estimate response: ' . json_encode($estimateData));

    $previewResponse = $runController->preview($makeRequest(['sample_size' => 2]), $campaign);
    $previewData = json_decode((string) $previewResponse->getContent(), true);
    $out('Preview count: ' . count($previewData['previews'] ?? []));

    $testResponse = $runController->testSend($makeRequest(['channels' => ['EMAIL']]), $campaign);
    $testData = json_decode((string) $testResponse->getContent(), true);
    $out('Test-send response: ' . json_encode($testData['results'] ?? []));

    $sendNowResponse = $runController->sendNow($makeRequest(), $campaign);
    $sendNowData = json_decode((string) $sendNowResponse->getContent(), true);
    $out('Send-now response: ' . json_encode($sendNowData));

    $scheduleAt = Carbon::now()->addMinutes(45)->format('Y-m-d H:i:s');
    $scheduleResponse = $runController->schedule($makeRequest(['scheduled_at' => $scheduleAt]), $campaign);
    $scheduleData = json_decode((string) $scheduleResponse->getContent(), true);
    $out('Schedule response: ' . json_encode($scheduleData));

    $dispatchQueue = (string) config('campaigns.queues.dispatch', 'campaigns-dispatch');
    $maintenanceQueue = (string) config('campaigns.queues.maintenance', 'campaigns-maintenance');
    $queueList = "{$dispatchQueue},{$maintenanceQueue},default";

    $out("Running queue worker for {$queueList}");
    Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--queue' => $queueList,
        '--timeout' => 90,
    ]);
    $queueOutput = Artisan::output();
    if (trim($queueOutput) !== '') {
        $out('Queue output:');
        echo $queueOutput . "\n";
    }

    $campaign->refresh();
    $runs = Campaign::query()
        ->whereKey($campaign->id)
        ->with([
            'runs' => fn ($query) => $query->latest()->limit(5),
        ])
        ->first()
        ?->runs
        ?->values()
        ?->all() ?? [];

    $recipientCount = $campaign->recipients()->count();
    $queuedRecipients = $campaign->recipients()->where('status', 'queued')->count();

    $out("Campaign #{$campaign->id} status={$campaign->status}");
    $out("Recipients total={$recipientCount}, queued={$queuedRecipients}");
    foreach ($runs as $run) {
        $out(
            "Run #{$run->id}: status={$run->status}, trigger={$run->trigger_type}, " .
            'scheduled_for=' . optional($run->scheduled_for)->toDateTimeString()
        );
    }

    $out('Smoke QA completed successfully.');
    exit(0);
} catch (Throwable $exception) {
    $out('Smoke QA failed: ' . $exception->getMessage());
    $out($exception->getTraceAsString());
    exit(1);
}

