<?php

namespace App\Services\Reservation;

use App\Models\Reservation;

class ReservationStatusTransitionResult
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly bool $performed,
        public readonly string $previousStatus,
        public readonly bool $statusChanged = false,
        public readonly bool $scheduleChanged = false,
        public readonly bool $attributesChanged = false
    ) {}
}
