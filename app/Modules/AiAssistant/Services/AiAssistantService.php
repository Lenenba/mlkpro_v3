<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\AiAssistant\DTO\AiAssistantResponse;
use App\Modules\AiAssistant\DTO\AiConversationContext;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use Illuminate\Support\Collection;

class AiAssistantService
{
    public function __construct(
        private readonly AiIntentDetector $intentDetector,
        private readonly AiPromptBuilder $promptBuilder,
        private readonly AiReservationOrchestrator $reservationOrchestrator,
        private readonly AiActionExecutor $actionExecutor,
        private readonly ContextualRecommendationEngine $recommendationEngine,
        private readonly ProactiveSuggestionEngine $suggestionEngine
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
        $recoveredReservationContext = $this->recentReservationContext($conversation, $message, $language, $detected->intent);
        $activeReservationFlow = $this->isActiveReservationFlow($conversation, $detected->intent) || $recoveredReservationContext !== null;
        $intent = $activeReservationFlow ? AiConversation::INTENT_RESERVATION : $detected->intent;
        $confidence = $activeReservationFlow
            ? max((float) $detected->confidence, (float) ($conversation->confidence_score ?? 0), 0.65)
            : (float) $detected->confidence;

        $conversationUpdate = [
            'detected_language' => $language,
            'intent' => $intent,
            'confidence_score' => $confidence,
        ];

        if (
            $activeReservationFlow
            && $conversation->status === AiConversation::STATUS_WAITING_HUMAN
            && ! $conversation->pendingActions()->exists()
        ) {
            $conversationUpdate['status'] = AiConversation::STATUS_OPEN;
        }

        $conversation->update($conversationUpdate);

        if (! $activeReservationFlow && ($smartReply = $this->smartGeneralReply($context, $conversation, $message, $language))) {
            return $smartReply;
        }

        if (! $activeReservationFlow && ($detected->confidence < 0.5 || $detected->intent === AiConversation::INTENT_HUMAN_REVIEW)) {
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

        $reply = match ($intent) {
            AiConversation::INTENT_RESERVATION => $this->reservationOrchestrator->handle($conversation->fresh() ?? $conversation, $settings, $recoveredReservationContext ?: $message, $language),
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

    private function smartGeneralReply(AiConversationContext $context, AiConversation $conversation, string $message, string $language): ?AiAssistantResponse
    {
        $settings = $context->settings;
        $recommendations = $this->recommendationEngine->analyze(
            $conversation,
            $settings,
            $context->tenant,
            $context->services,
            [],
            $message,
            $language
        );

        if ($recommendations['refund_or_payment_conflict'] ?? false) {
            $action = $this->actionExecutor->createAction($conversation, AiAction::TYPE_REQUEST_HUMAN_REVIEW, [
                'reason' => 'payment_or_refund_conflict',
                'message' => $message,
            ], false);
            $conversation->update(['status' => AiConversation::STATUS_WAITING_HUMAN]);

            return new AiAssistantResponse(
                message: $this->suggestionEngine->humanReviewSuggestion($language)->message,
                status: AiConversation::STATUS_WAITING_HUMAN,
                actions: [$action]
            );
        }

        if ($recommendations['last_service'] ?? null) {
            $suggestions = $this->suggestionEngine->suggestions(
                $conversation,
                $settings,
                $context->tenant,
                $context->services,
                [],
                $recommendations,
                $language
            );

            return new AiAssistantResponse(
                message: (string) $suggestions->pluck('message')->filter()->first(),
                status: AiConversation::STATUS_OPEN
            );
        }

        if ($recommendations['is_price_question'] ?? false) {
            return new AiAssistantResponse(
                message: $this->priceReply($context->services, $language),
                status: AiConversation::STATUS_OPEN
            );
        }

        $serviceSuggestions = $recommendations['service_recommendations'] ?? collect();
        if ((bool) $settings->enable_proactive_suggestions && $serviceSuggestions->isNotEmpty()) {
            $names = $serviceSuggestions->pluck('name')->map(fn ($name): string => (string) $name)->implode(' ou ');

            return new AiAssistantResponse(
                message: $language === 'fr'
                    ? "Je peux vous orienter vers {$names}. Voulez-vous que je regarde les disponibilites pour l un de ces services?"
                    : "I can point you toward {$names}. Would you like me to check availability for one of these services?",
                status: AiConversation::STATUS_OPEN
            );
        }

        if ($recommendations['is_service_exploration'] ?? false) {
            $services = $context->services
                ->take(3)
                ->values()
                ->map(fn ($service, int $index): string => ($index + 1).'. '.(string) $service->name)
                ->implode('; ');

            return new AiAssistantResponse(
                message: $language === 'fr'
                    ? 'Pas de souci, je peux vous guider. '.($services !== '' ? "Voici quelques options: {$services}. " : '').'Est-ce plutot pour reserver, comparer les prix, ou poser une question a l equipe?'
                    : 'No problem, I can guide you. '.($services !== '' ? "Here are a few options: {$services}. " : '').'Is this for booking, comparing prices, or asking the team a question?',
                status: AiConversation::STATUS_OPEN
            );
        }

        return null;
    }

    private function priceReply(Collection $services, string $language): string
    {
        $priced = $services
            ->filter(fn ($service): bool => (float) ($service->price ?? 0) > 0)
            ->take(3)
            ->values();

        if ($priced->isEmpty()) {
            return $language === 'fr'
                ? 'Je peux vous orienter selon le type de service, mais je prefere faire confirmer les prix exacts par l equipe.'
                : 'I can guide you by service type, but I prefer to have the team confirm exact prices.';
        }

        $lines = $priced
            ->map(fn ($service): string => (string) $service->name.' - '.number_format((float) $service->price, 2).' '.(string) ($service->currency_code ?: ''))
            ->implode('; ');

        return $language === 'fr'
            ? "Voici les prix que je vois: {$lines}. Voulez-vous que je verifie les disponibilites pour l un de ces services?"
            : "Here are the prices I see: {$lines}. Would you like me to check availability for one of these services?";
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

    private function isActiveReservationFlow(AiConversation $conversation, string $detectedIntent): bool
    {
        if ($detectedIntent === AiConversation::INTENT_HUMAN_REVIEW) {
            return false;
        }

        if ($detectedIntent === AiConversation::INTENT_RESERVATION) {
            return true;
        }

        $draft = data_get($conversation->metadata, 'reservation_draft', []);

        return $conversation->intent === AiConversation::INTENT_RESERVATION
            || (is_array($draft) && $draft !== []);
    }

    private function recentReservationContext(AiConversation $conversation, string $message, string $language, string $detectedIntent): ?string
    {
        if (in_array($detectedIntent, [AiConversation::INTENT_RESERVATION, AiConversation::INTENT_HUMAN_REVIEW], true)) {
            return null;
        }

        $draft = data_get($conversation->metadata, 'reservation_draft', []);
        if (is_array($draft) && $draft !== []) {
            return null;
        }

        $recentMessages = $conversation->messages()
            ->where('sender_type', AiMessage::SENDER_USER)
            ->latest('id')
            ->limit(4)
            ->pluck('content')
            ->reverse()
            ->map(fn ($content): string => trim((string) $content))
            ->filter()
            ->values();

        $currentMessage = trim($message);
        if ($currentMessage !== '' && ! $recentMessages->contains($currentMessage)) {
            $recentMessages->push($currentMessage);
        }

        if ($recentMessages->count() < 2) {
            return null;
        }

        $context = trim($recentMessages->implode(' '));
        if ($context === '' || $context === $currentMessage) {
            return null;
        }

        $contextIntent = $this->intentDetector->detect($context, $language);

        return $contextIntent->intent === AiConversation::INTENT_RESERVATION ? $context : null;
    }
}
