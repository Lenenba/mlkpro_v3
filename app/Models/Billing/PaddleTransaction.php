<?php

namespace App\Models\Billing;

use Laravel\Paddle\Transaction as CashierTransaction;

class PaddleTransaction extends CashierTransaction
{
    protected $table = 'paddle_transactions';
}

