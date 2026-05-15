<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Product;
use App\Models\User;
use App\Modules\AiAssistant\DTO\AiConversationContext;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Services\ReservationAvailabilityService;

class AiPromptBuilder
{
    public function __construct(
        private readonly AiKnowledgeResolver $knowledgeResolver,
        private readonly ReservationAvailabilityService $availabilityService
    ) {}

    public function context(AiConversation $conversation): AiConversationContext
    {
        $tenant = User::query()->findOrFail((int) $conversation->tenant_id);
        $settings = AiAssistantSetting::firstOrCreateForTenant($tenant);
        $services = Product::query()
            ->services()
            ->where('user_id', (int) $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'price', 'currency_code', 'unit']);

        $messages = $conversation->messages()
            ->latest()
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        return new AiConversationContext(
            tenant: $tenant,
            settings: $settings,
            conversation: $conversation,
            services: $services,
            messages: $messages,
            knowledgeItems: $this->knowledgeResolver->activeForTenant((int) $tenant->id),
            bookingRules: [
                'timezone' => $this->availabilityService->timezoneForAccount($tenant),
                'settings' => $this->availabilityService->resolveSettings((int) $tenant->id),
            ]
        );
    }

    public function systemPrompt(AiConversationContext $context, string $language): string
    {
        $settings = $context->settings;
        $tenant = $context->tenant;
        $businessName = $tenant->company_name ?: $tenant->name ?: config('app.name');
        $services = $context->services
            ->map(fn (Product $service): string => sprintf(
                '- %s%s',
                $service->name,
                $service->description ? ': '.$service->description : ''
            ))
            ->implode("\n");
        $recentMessages = $context->messages
            ->map(fn ($message): string => sprintf(
                '- %s: %s',
                (string) $message->sender_type,
                trim((string) $message->content)
            ))
            ->implode("\n");
        $allowedActions = collect([
            'create_prospect' => $settings->allow_create_prospect,
            'create_client' => $settings->allow_create_client,
            'create_reservation' => $settings->allow_create_reservation,
            'reschedule_reservation' => $settings->allow_reschedule_reservation,
            'create_task' => $settings->allow_create_task,
        ])
            ->filter()
            ->keys()
            ->implode(', ');

        return <<<PROMPT
You are {$settings->assistant_name}, the virtual assistant for {$businessName}.
You help clients with reservations, service questions, and follow-ups.
You must communicate in {$language}.

Business context:
{$settings->business_context}

Available services:
{$services}

Booking rules:
Timezone: {$context->bookingRules['timezone']}

Allowed actions:
{$allowedActions}

Recent conversation:
{$recentMessages}

Important rules:
- Never invent data.
- Never promise something that is not available.
- If you are unsure, request human review.
- Ask one question at a time.
- Keep the tone warm, clear, and professional.
- When the user wants a reservation, collect all required information before proposing slots.
- If the user is new, create a prospect, not a client.
PROMPT;
    }
}
