<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\LoyaltyPointService;

class PaymentObserver
{
    public function __construct(private readonly LoyaltyPointService $loyaltyPointService)
    {
    }

    public function created(Payment $payment): void
    {
        $this->loyaltyPointService->awardForPayment($payment);
        $this->loyaltyPointService->refundForPayment($payment);
    }

    public function updated(Payment $payment): void
    {
        if (!$payment->wasChanged('status')) {
            return;
        }

        $this->loyaltyPointService->awardForPayment($payment);
        $this->loyaltyPointService->refundForPayment($payment);
    }
}

