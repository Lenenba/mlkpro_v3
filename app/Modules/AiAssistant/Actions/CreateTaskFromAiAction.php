<?php

namespace App\Modules\AiAssistant\Actions;

use App\Modules\AiAssistant\Models\AiAction;
use Illuminate\Validation\ValidationException;

class CreateTaskFromAiAction
{
    public function execute(AiAction $action): never
    {
        throw ValidationException::withMessages([
            'action' => ['Task creation is planned for phase 2.'],
        ]);
    }
}
