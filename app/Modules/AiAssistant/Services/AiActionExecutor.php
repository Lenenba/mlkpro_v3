<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Modules\AiAssistant\Actions\CreateProspectFromAiAction;
use App\Modules\AiAssistant\Actions\CreateReservationFromAiAction;
use App\Modules\AiAssistant\Actions\CreateTaskFromAiAction;
use App\Modules\AiAssistant\Actions\RequestHumanReviewAction;
use App\Modules\AiAssistant\Actions\RescheduleReservationFromAiAction;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class AiActionExecutor
{
    public function __construct(
        private readonly CreateProspectFromAiAction $createProspect,
        private readonly CreateReservationFromAiAction $createReservation,
        private readonly RescheduleReservationFromAiAction $rescheduleReservation,
        private readonly CreateTaskFromAiAction $createTask,
        private readonly RequestHumanReviewAction $requestHumanReview
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createAction(AiConversation $conversation, string $type, array $payload, bool $pending = true): AiAction
    {
        $action = AiAction::query()->create([
            'tenant_id' => (int) $conversation->tenant_id,
            'conversation_id' => (int) $conversation->id,
            'action_type' => $type,
            'status' => $pending ? AiAction::STATUS_PENDING : AiAction::STATUS_APPROVED,
            'input_payload' => $payload,
        ]);

        if (! $pending) {
            $this->execute($action);
        }

        return $action->fresh() ?? $action;
    }

    public function approve(AiAction $action): AiAction
    {
        if (! $action->isPending()) {
            return $action;
        }

        $action->update([
            'status' => AiAction::STATUS_APPROVED,
        ]);

        return $this->execute($action);
    }

    public function reject(AiAction $action, ?string $reason = null): AiAction
    {
        if (! $action->isPending()) {
            return $action;
        }

        $action->update([
            'status' => AiAction::STATUS_REJECTED,
            'error_message' => $reason,
        ]);

        return $action;
    }

    public function execute(AiAction $action): AiAction
    {
        try {
            $result = match ($action->action_type) {
                AiAction::TYPE_CREATE_PROSPECT => $this->createProspect->execute($action),
                AiAction::TYPE_CREATE_RESERVATION => $this->createReservation->execute($action),
                AiAction::TYPE_RESCHEDULE_RESERVATION => $this->rescheduleReservation->execute($action),
                AiAction::TYPE_CREATE_TASK => $this->createTask->execute($action),
                AiAction::TYPE_REQUEST_HUMAN_REVIEW => $this->requestHumanReview->execute($action),
                default => null,
            };

            $action->update([
                'status' => AiAction::STATUS_EXECUTED,
                'output_payload' => $this->outputPayload($result),
                'error_message' => null,
                'executed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $action->update([
                'status' => AiAction::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $action->fresh() ?? $action;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outputPayload(mixed $result): ?array
    {
        if ($result instanceof LeadRequest) {
            return [
                'prospect_id' => (int) $result->id,
                'contact_name' => $result->contact_name,
            ];
        }

        if ($result instanceof Reservation) {
            return [
                'reservation_id' => (int) $result->id,
                'status' => $result->status,
                'starts_at' => $result->starts_at?->toIso8601String(),
                'service_name' => $result->service?->name,
            ];
        }

        if ($result instanceof Model) {
            return [
                'id' => (int) $result->getKey(),
            ];
        }

        return null;
    }
}
