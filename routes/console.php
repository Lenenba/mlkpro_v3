<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Work;
use App\Services\WorkBillingService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('workflow:auto-validate', function (WorkBillingService $billingService) {
    $cutoff = now()->subHours(48);
    $works = Work::query()
        ->where('status', Work::STATUS_PENDING_REVIEW)
        ->where('updated_at', '<=', $cutoff)
        ->get();

    $count = 0;
    foreach ($works as $work) {
        $work->status = Work::STATUS_AUTO_VALIDATED;
        $work->save();
        $billingService->createInvoiceFromWork($work);
        $count += 1;
    }

    $this->info("Auto-validated {$count} jobs.");
})->purpose('Auto validate jobs after 48h')->hourly();
