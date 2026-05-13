<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\AiAssistant\DTO\AiAssistantResponse;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;

class AiAssistantService
{
    public function __construct(
        private readonly AiIntentDetector $intentDetector,
        private readonly AiPromptBuilder $promptBuilder,
        private readonly AiReservationOrchestrator $reservationOrchestrator,
        private readonly AiActionExecutor $actionExecutor
    ) {}

    public function greetingFor(User $tenant): string
    {
        $settings = AiAssistantSetting::firstOrCreateForTenant($tenant);

        return (string) ($settings->greeting_message ?: AiAssistantSetting::defaultsFor($tenant)['greeting_message']);
    }

    public function handleUserMessage(AiConversation $conversation, string $message): AiAssistantResponse
    {
        $context = $this->promptBuilder->context($conversation);
        $settings = $context->settings;
        $detected = $this->intentDetector->detect($message, $conversation->detected_language ?: $settings->default_language);
        $language = in_array($detected->language, $settings->supported_languages ?? [], true)
            ? $detected->language
            : (string) $settings->default_language;

        $conversation->update([
            'detected_language' => $language,
            'intent' => $detected->intent,
            'confidence_score' => $detected->confidence,
        ]);

        if ($detected->confidence < 0.5 || $detected->intent === AiConversation::INTENT_HUMAN_REVIEW) {
            $action = $this->actionExecutor->createAction($conversation, AiAction::TYPE_REQUEST_HUMAN_REVIEW, [
                'reason' => 'low_confidence',
                'message' => $message,
                'confidence' => $detected->confidence,
            ], false);
            $conversation->update([
                'status' => AiConversation::STATUS_WAITING_HUMAN,
            ]);

            return new AiAssistantResponse(
                message: $this->fallbackMessage($settings, $language),
                status: AiConversation::STATUS_WAITING_HUMAN,
                actions: [$action]
            );
        }

        $reply = match ($detected->intent) {
            AiConversation::INTENT_RESERVATION => $this->reservationOrchestrator->handle($conversation->fresh() ?? $conversation, $settings, $message, $language),
            AiConversation::INTENT_RESCHEDULE => $this->rescheduleReply($settings, $conversation, $language),
            default => $this->generalReply($settings, $language),
        };

        return new AiAssistantResponse(
            message: $reply,
            status: (string) (($conversation->fresh()?->status) ?? $conversation->status)
        );
    }

    public function recordAssistantMessage(AiConversation $conversation, AiAssistantResponse $response): AiMessage
    {
        return AiMessage::query()->create([
            'conversation_id' => (int) $conversation->id,
            'sender_type' => AiMessage::SENDER_ASSISTANT,
            'content' => $response->message,
            'payload' => [
                'status' => $response->status,
                'action_ids' => collect($response->actions)->pluck('id')->values()->all(),
                'metadata' => $response->metadata,
            ],
        ]);
    }

    private function generalReply(AiAssistantSetting $settings, string $language): string
    {
        return $language === 'fr'
            ? "Je peux vous aider avec une reservation ou transmettre votre demande a l'equipe. Que souhaitez-vous faire?"
            : 'I can help with a booking or send your request to the team. What would you like to do?';
    }

    private function rescheduleReply(AiAssistantSetting $settings, AiConversation $conversation, string $language): string
    {
        if (! $settings->allow_reschedule_reservation) {
            $this->actionExecutor->createAction($conversation, AiAction::TYPE_REQUEST_HUMAN_REVIEW, [
                'reason' => 'reschedule_phase_2',
            ], false);
            $conversation->update(['status' => AiConversation::STATUS_WAITING_HUMAN]);

            return $this->fallbackMessage($settings, $language);
        }

        return $language === 'fr'
            ? 'Je peux vous aider a replanifier. Quel est le numero de telephone ou email utilise pour la reservation?'
            : 'I can help you reschedule. What phone number or email was used for the booking?';
    }

    private function fallbackMessage(AiAssistantSetting $settings, string $language): string
    {
        if ($settings->fallback_message) {
            return (string) $settings->fallback_message;
        }

        return $language === 'fr'
            ? "Je vais transmettre votre demande a l'equipe pour verification."
            : 'I will send your request to the team for review.';
    }
}
