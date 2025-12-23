<?php

namespace App\Models\Billing;

use Laravel\Paddle\Customer as CashierCustomer;

class PaddleCustomer extends CashierCustomer
{
    protected $table = 'paddle_customers';
}

