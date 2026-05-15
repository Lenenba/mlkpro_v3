<?php

namespace App\Modules\AiAssistant\Actions;

use App\Modules\AiAssistant\Models\AiAction;
use Illuminate\Validation\ValidationException;

class RescheduleReservationFromAiAction
{
    public function execute(AiAction $action): never
    {
        throw ValidationException::withMessages([
            'action' => ['Reservation rescheduling is planned for phase 2.'],
        ]);
    }
}
