<?php

namespace App\Exceptions;

use RuntimeException;

class ReceiptDeliveryInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('receipt_delivery_in_progress');
    }
}
