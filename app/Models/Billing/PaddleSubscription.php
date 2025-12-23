<?php

namespace App\Models\Billing;

use Laravel\Paddle\Subscription as CashierSubscription;

class PaddleSubscription extends CashierSubscription
{
    protected $table = 'paddle_subscriptions';

    public function getForeignKey()
    {
        return 'subscription_id';
    }
}
