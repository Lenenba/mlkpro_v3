<?php

namespace App\Models\Billing;

use Laravel\Paddle\SubscriptionItem as CashierSubscriptionItem;

class PaddleSubscriptionItem extends CashierSubscriptionItem
{
    protected $table = 'paddle_subscription_items';
}

