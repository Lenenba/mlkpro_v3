<?php

namespace App\Providers;

use App\Models\Billing\PaddleCustomer;
use App\Models\Billing\PaddleSubscription;
use App\Models\Billing\PaddleSubscriptionItem;
use App\Models\Billing\PaddleTransaction;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(PaddleCustomer::class);
        Cashier::useSubscriptionModel(PaddleSubscription::class);
        Cashier::useSubscriptionItemModel(PaddleSubscriptionItem::class);
        Cashier::useTransactionModel(PaddleTransaction::class);

        Vite::prefetch(concurrency: 3);
    }
}
