<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Requests\ApproveAiActionRequest;
use App\Modules\AiAssistant\Services\AiActionExecutor;

class AiActionController extends Controller
{
    public function __construct(
        private readonly AiActionExecutor $executor
    ) {}

    public function approve(ApproveAiActionRequest $request, AiAction $action)
    {
        $action->loadMissing('conversation');
        $this->authorize('manageActions', $action->conversation);

        $updated = $this->executor->approve($action);

        return response()->json([
            'message' => $updated->status === AiAction::STATUS_EXECUTED
                ? 'AI action executed.'
                : 'AI action updated.',
            'action' => $this->payload($updated),
        ]);
    }

    public function reject(ApproveAiActionRequest $request, AiAction $action)
    {
        $action->loadMissing('conversation');
        $this->authorize('manageActions', $action->conversation);

        $updated = $this->executor->reject($action, $request->validated('reason'));

        return response()->json([
            'message' => 'AI action rejected.',
            'action' => $this->payload($updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiAction $action): array
    {
        return [
            'id' => (int) $action->id,
            'action_type' => (string) $action->action_type,
            'status' => (string) $action->status,
            'input_payload' => $action->input_payload,
            'output_payload' => $action->output_payload,
            'error_message' => $action->error_message,
            'executed_at' => $action->executed_at?->toIso8601String(),
        ];
    }
}
