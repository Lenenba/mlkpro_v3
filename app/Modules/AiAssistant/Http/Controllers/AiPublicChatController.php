<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Requests\SendAiMessageRequest;
use App\Modules\AiAssistant\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AiPublicChatController extends Controller
{
    public function __construct(
        private readonly AiAssistantService $assistant
    ) {}

    public function page(string $company)
    {
        $tenant = $this->resolveTenant($company);
        $setting = AiAssistantSetting::firstOrCreateForTenant($tenant);

        abort_unless($setting->enabled, 404);

        return Inertia::render('Public/AiAssistantChat', [
            'company' => [
                'name' => $tenant->company_name ?: $tenant->name,
                'slug' => $tenant->company_slug,
                'logo_url' => $this->publicLogoUrl($tenant),
            ],
            'assistant' => [
                'name' => (string) $setting->assistant_name,
                'default_language' => (string) $setting->default_language,
            ],
            'endpoints' => [
                'create' => route('public.ai-assistant.conversations.store'),
                'message' => route('public.ai-assistant.conversations.messages.store', ['conversation' => '__conversation__']),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:120'],
            'channel' => ['nullable', 'string', Rule::in([
                AiConversation::CHANNEL_WEB_CHAT,
                AiConversation::CHANNEL_PUBLIC_RESERVATION,
            ])],
            'visitor_name' => ['nullable', 'string', 'max:191'],
            'visitor_email' => ['nullable', 'email', 'max:191'],
            'visitor_phone' => ['nullable', 'string', 'max:80'],
            'metadata' => ['nullable', 'array'],
        ]);

        $tenant = $this->resolveTenant((string) $validated['company']);
        $setting = AiAssistantSetting::firstOrCreateForTenant($tenant);
        if (! $setting->enabled) {
            abort(404);
        }

        $channel = $validated['channel'] ?? AiConversation::CHANNEL_WEB_CHAT;
        $publicContext = $validated['metadata'] ?? [];
        $metadata = [
            'public_context' => $publicContext,
        ];
        $conversationPayload = [
            'tenant_id' => (int) $tenant->id,
            'channel' => $channel,
            'visitor_name' => $validated['visitor_name'] ?? null,
            'visitor_email' => $validated['visitor_email'] ?? null,
            'visitor_phone' => $validated['visitor_phone'] ?? null,
            'detected_language' => $setting->default_language,
        ];

        if ($channel === AiConversation::CHANNEL_PUBLIC_RESERVATION) {
            $metadata['reservation_draft'] = $this->reservationDraftFromPublicContext($publicContext, $validated, $tenant);
            $conversationPayload['intent'] = AiConversation::INTENT_RESERVATION;
            $conversationPayload['confidence_score'] = 0.65;
        }

        $conversationPayload['metadata'] = $metadata;
        $conversation = AiConversation::query()->create($conversationPayload);

        $message = AiMessage::query()->create([
            'conversation_id' => (int) $conversation->id,
            'sender_type' => AiMessage::SENDER_ASSISTANT,
            'content' => $this->assistant->greetingFor($tenant),
            'payload' => [
                'kind' => 'greeting',
            ],
        ]);

        return response()->json([
            'conversation' => [
                'uuid' => (string) $conversation->public_uuid,
                'status' => (string) $conversation->status,
            ],
            'message' => $this->messagePayload($message),
        ], 201);
    }

    public function message(SendAiMessageRequest $request, string $conversation)
    {
        $conversationModel = AiConversation::query()
            ->where('public_uuid', $conversation)
            ->firstOrFail();
        $setting = AiAssistantSetting::query()
            ->forTenant((int) $conversationModel->tenant_id)
            ->firstOrFail();

        if (! $setting->enabled) {
            abort(404);
        }

        $userMessage = AiMessage::query()->create([
            'conversation_id' => (int) $conversationModel->id,
            'sender_type' => AiMessage::SENDER_USER,
            'content' => (string) $request->validated('message'),
            'payload' => [
                'ip' => $request->ip(),
            ],
        ]);

        $response = $this->assistant->handleUserMessage($conversationModel, (string) $userMessage->content);
        $assistantMessage = $this->assistant->recordAssistantMessage($conversationModel, $response);

        return response()->json([
            'conversation' => [
                'uuid' => (string) $conversationModel->public_uuid,
                'status' => (string) ($conversationModel->fresh()?->status ?? $conversationModel->status),
            ],
            'messages' => [
                $this->messagePayload($userMessage),
                $this->messagePayload($assistantMessage),
            ],
        ]);
    }

    private function resolveTenant(string $company): User
    {
        $company = trim($company);
        if ($company === '' || is_numeric($company)) {
            abort(404);
        }

        $tenant = User::query()->where('company_slug', $company)->first();

        if (! $tenant || $tenant->isSuspended()) {
            abort(404);
        }

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $publicContext
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function reservationDraftFromPublicContext(array $publicContext, array $validated, User $tenant): array
    {
        $draft = [];

        $serviceId = (int) ($publicContext['selected_service_id'] ?? 0);
        if ($serviceId > 0) {
            $service = Product::query()
                ->services()
                ->where('user_id', (int) $tenant->id)
                ->where('is_active', true)
                ->whereKey($serviceId)
                ->first(['id', 'name']);

            if ($service) {
                $draft['service_id'] = (int) $service->id;
                $draft['service_name'] = (string) $service->name;
            }
        }

        foreach ([
            'visitor_name' => 'contact_name',
            'visitor_email' => 'contact_email',
            'visitor_phone' => 'contact_phone',
        ] as $inputKey => $draftKey) {
            $value = trim((string) ($validated[$inputKey] ?? ''));
            if ($value !== '') {
                $draft[$draftKey] = $value;
            }
        }

        $selectedDate = $this->dateString($publicContext['selected_date'] ?? null);
        if ($selectedDate) {
            $draft['preferred_date'] = $selectedDate;
        }

        $selectedTime = $publicContext['selected_time'] ?? null;
        $time = $this->timeString($selectedTime);
        if ($time) {
            $draft['preferred_time'] = $time;
        }

        if (! isset($draft['preferred_date'])) {
            $dateFromTime = $this->dateString($selectedTime);
            if ($dateFromTime) {
                $draft['preferred_date'] = $dateFromTime;
            }
        }

        foreach (['booking_link_id', 'booking_link_slug', 'booking_link_name'] as $key) {
            if (array_key_exists($key, $publicContext)) {
                $draft[$key] = $publicContext[$key];
            }
        }

        return $draft;
    }

    private function dateString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/\d{4}-\d{1,2}-\d{1,2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function timeString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/\b([01]?\d|2[0-3])[:h]([0-5]\d)\b/u', $value, $matches) === 1) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT).':'.$matches[2];
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function publicLogoUrl(User $tenant): ?string
    {
        return $tenant->company_logo ? $tenant->company_logo_url : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(AiMessage $message): array
    {
        return [
            'sender_type' => (string) $message->sender_type,
            'content' => (string) $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
