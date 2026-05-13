<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Requests\SendAiMessageRequest;
use App\Modules\AiAssistant\Services\AiAssistantService;
use Illuminate\Http\Request;
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
                'logo_url' => $tenant->company_logo_url,
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

        $conversation = AiConversation::query()->create([
            'tenant_id' => (int) $tenant->id,
            'channel' => $validated['channel'] ?? AiConversation::CHANNEL_WEB_CHAT,
            'visitor_name' => $validated['visitor_name'] ?? null,
            'visitor_email' => $validated['visitor_email'] ?? null,
            'visitor_phone' => $validated['visitor_phone'] ?? null,
            'detected_language' => $setting->default_language,
            'metadata' => [
                'public_context' => $validated['metadata'] ?? [],
            ],
        ]);

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
