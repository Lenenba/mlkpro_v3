<?php

namespace App\Modules\AiAssistant\DTO;

class ProactiveSuggestion
{
    public const TYPE_SERVICE_RECOMMENDATION = 'service_recommendation';

    public const TYPE_TIME_RECOMMENDATION = 'time_recommendation';

    public const TYPE_STAFF_RECOMMENDATION = 'staff_recommendation';

    public const TYPE_EARLIEST_SLOT_RECOMMENDATION = 'earliest_slot_recommendation';

    public const TYPE_ALTERNATIVE_SLOT_RECOMMENDATION = 'alternative_slot_recommendation';

    public const TYPE_MISSING_INFORMATION_HINT = 'missing_information_hint';

    public const TYPE_CLARIFICATION_HINT = 'clarification_hint';

    public const TYPE_UPSELL_RECOMMENDATION = 'upsell_recommendation';

    public const TYPE_HUMAN_REVIEW_RECOMMENDATION = 'human_review_recommendation';

    public const TYPE_POLICY_EXPLANATION = 'policy_explanation';

    public const TYPE_NEXT_STEP_GUIDANCE = 'next_step_guidance';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly int $priority,
        public readonly string $label,
        public readonly string $message,
        public readonly ?string $actionType = null,
        public readonly array $payload = [],
        public readonly bool $requiresUserConfirmation = true,
        public readonly float $confidenceScore = 0.7,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'priority' => $this->priority,
            'label' => $this->label,
            'message' => $this->message,
            'actionType' => $this->actionType,
            'payload' => $this->payload,
            'requiresUserConfirmation' => $this->requiresUserConfirmation,
            'confidenceScore' => $this->confidenceScore,
        ];
    }
}
