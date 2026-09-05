<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\TeamMember;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Requests\SendAiMessageRequest;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Services\TenantBrandingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AiPublicChatController extends Controller
{
    /** @var array<int, string> */
    private const PUBLIC_CONTEXT_KEYS = [
        'source',
        'booking_link_id',
        'booking_link_slug',
        'booking_link_name',
        'selected_service_id',
        'selected_service_name',
        'selected_date',
        'selected_time',
        'selected_team_member_id',
    ];

    public function __construct(
        private readonly AiAssistantService $assistant,
        private readonly TenantBrandingResolver $tenantBrandingResolver,
    ) {}

    public function page(string $company)
    {
        $tenant = $this->resolveTenant($company);
        $setting = AiAssistantSetting::firstOrCreateForTenant($tenant);

        abort_unless($setting->enabled, 404);
        $tenantBranding = $this->tenantBrandingResolver->forAccountOwner($tenant);

        return Inertia::render('Public/AiAssistantChat', [
            'company' => [
                'name' => $tenantBranding['name'],
                'slug' => $tenant->company_slug,
                'logo_url' => $tenantBranding['custom_logo_url'],
                'custom_logo_url' => $tenantBranding['custom_logo_url'],
                'has_custom_logo' => $tenantBranding['has_custom_logo'],
            ],
            'assistant' => [
                'name' => (string) $setting->assistant_name,
                'default_language' => (string) $setting->default_language,
            ],
            'endpoints' => [
                'create' => route('public.ai-assistant.conversations.store'),
                'show' => route('public.ai-assistant.conversations.show', ['conversation' => '__conversation__']),
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
        $publicContext = Arr::only($validated['metadata'] ?? [], self::PUBLIC_CONTEXT_KEYS);
        $metadata = [
            'public_context' => $publicContext,
            'public_contact_context' => Arr::only($validated, [
                'visitor_name',
                'visitor_email',
                'visitor_phone',
            ]),
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
            'quick_replies' => [],
        ], 201);
    }

    public function show(Request $request, string $conversation)
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:120'],
            'channel' => ['nullable', 'string', Rule::in([
                AiConversation::CHANNEL_WEB_CHAT,
                AiConversation::CHANNEL_PUBLIC_RESERVATION,
            ])],
        ]);
        $tenant = $this->resolveTenant((string) $validated['company']);
        $setting = AiAssistantSetting::query()
            ->forTenant((int) $tenant->id)
            ->firstOrFail();

        abort_unless($setting->enabled, 404);

        $conversationModel = AiConversation::query()
            ->forTenant((int) $tenant->id)
            ->where('public_uuid', $conversation)
            ->when(
                $validated['channel'] ?? null,
                fn ($query, $channel) => $query->where('channel', $channel)
            )
            ->with('messages')
            ->firstOrFail();

        return response()->json([
            'conversation' => [
                'uuid' => (string) $conversationModel->public_uuid,
                'status' => (string) $conversationModel->status,
            ],
            'messages' => $conversationModel->messages
                ->map(fn (AiMessage $message): array => $this->messagePayload($message))
                ->values()
                ->all(),
            'quick_replies' => $this->quickReplies($conversationModel),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function message(SendAiMessageRequest $request, string $conversation)
    {
        $validated = $request->validated();
        $tenant = isset($validated['company'])
            ? $this->resolveTenant((string) $validated['company'])
            : null;
        $conversationModel = AiConversation::query()
            ->when($tenant, fn ($query) => $query->forTenant((int) $tenant->id))
            ->when(
                $validated['channel'] ?? null,
                fn ($query, $channel) => $query->where('channel', $channel)
            )
            ->where('public_uuid', $conversation)
            ->firstOrFail();
        $tenant ??= User::query()->findOrFail((int) $conversationModel->tenant_id);

        abort_if($tenant->isSuspended(), 404);

        $setting = AiAssistantSetting::query()
            ->forTenant((int) $conversationModel->tenant_id)
            ->firstOrFail();

        if (! $setting->enabled) {
            abort(404);
        }

        $this->synchronizePublicReservationContext($conversationModel, $validated, $tenant);

        $userMessage = AiMessage::query()->create([
            'conversation_id' => (int) $conversationModel->id,
            'sender_type' => AiMessage::SENDER_USER,
            'content' => (string) $validated['message'],
            'payload' => [
                'ip' => $request->ip(),
            ],
        ]);

        $response = $this->assistant->handleUserMessage($conversationModel, (string) $userMessage->content);
        $assistantMessage = $this->assistant->recordAssistantMessage($conversationModel, $response);
        $freshConversation = $conversationModel->fresh() ?? $conversationModel;

        return response()->json([
            'conversation' => [
                'uuid' => (string) $conversationModel->public_uuid,
                'status' => (string) $freshConversation->status,
            ],
            'messages' => [
                $this->messagePayload($userMessage),
                $this->messagePayload($assistantMessage),
            ],
            'quick_replies' => $this->quickReplies($freshConversation),
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

        $teamMemberId = (int) ($publicContext['selected_team_member_id'] ?? 0);
        if ($teamMemberId > 0) {
            $teamMember = TeamMember::query()
                ->forAccount((int) $tenant->id)
                ->active()
                ->with('user:id,name')
                ->find($teamMemberId);

            if ($teamMember) {
                $draft['preferred_team_member_id'] = (int) $teamMember->id;
                $draft['preferred_team_member_name'] = $teamMember->user?->name;
            }
        }

        foreach (['booking_link_id', 'booking_link_slug', 'booking_link_name'] as $key) {
            if (array_key_exists($key, $publicContext)) {
                $draft[$key] = $publicContext[$key];
            }
        }

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function synchronizePublicReservationContext(
        AiConversation $conversation,
        array $validated,
        User $tenant
    ): void {
        if (
            $conversation->channel !== AiConversation::CHANNEL_PUBLIC_RESERVATION
            || ! isset($validated['metadata'])
            || ! is_array($validated['metadata'])
        ) {
            return;
        }

        $metadata = $conversation->metadata ?? [];
        $previousContext = (array) data_get($metadata, 'public_context', []);
        $publicContext = Arr::only($validated['metadata'], self::PUBLIC_CONTEXT_KEYS);
        $incomingDraft = $this->reservationDraftFromPublicContext($publicContext, $validated, $tenant);
        $draft = (array) data_get($metadata, 'reservation_draft', []);
        $persistedPublicContext = array_replace(
            $previousContext,
            Arr::only($publicContext, [
                'source',
                'booking_link_id',
                'booking_link_slug',
                'booking_link_name',
            ])
        );
        $reservationContextChanged = false;

        if (
            isset($incomingDraft['service_id'])
            && $this->contextValueChanged($previousContext, $publicContext, 'selected_service_id')
        ) {
            unset(
                $draft['preferred_date'],
                $draft['preferred_date_start'],
                $draft['preferred_date_end'],
                $draft['preferred_date_label'],
                $draft['preferred_time'],
                $draft['preferred_time_start'],
                $draft['preferred_time_end'],
                $draft['preferred_time_label'],
                $draft['preferred_team_member_id'],
                $draft['preferred_team_member_name']
            );
            $draft['service_id'] = $incomingDraft['service_id'];
            $draft['service_name'] = $incomingDraft['service_name'];
            $persistedPublicContext['selected_service_id'] = $publicContext['selected_service_id'];
            $persistedPublicContext['selected_service_name'] = $incomingDraft['service_name'];
            unset(
                $persistedPublicContext['selected_date'],
                $persistedPublicContext['selected_time'],
                $persistedPublicContext['selected_team_member_id']
            );
            $reservationContextChanged = true;
        }

        if (
            isset($incomingDraft['preferred_date'])
            && $this->contextValueChanged($previousContext, $publicContext, 'selected_date')
        ) {
            unset(
                $draft['preferred_date'],
                $draft['preferred_date_start'],
                $draft['preferred_date_end'],
                $draft['preferred_date_label'],
                $draft['preferred_time'],
                $draft['preferred_time_start'],
                $draft['preferred_time_end'],
                $draft['preferred_time_label'],
                $draft['preferred_team_member_id'],
                $draft['preferred_team_member_name']
            );
            $draft['preferred_date'] = $incomingDraft['preferred_date'];
            $persistedPublicContext['selected_date'] = $publicContext['selected_date'];
            unset($persistedPublicContext['selected_time'], $persistedPublicContext['selected_team_member_id']);
            $reservationContextChanged = true;
        }

        if (
            isset($incomingDraft['preferred_time'])
            && $this->contextValueChanged($previousContext, $publicContext, 'selected_time')
        ) {
            unset(
                $draft['preferred_time'],
                $draft['preferred_time_start'],
                $draft['preferred_time_end'],
                $draft['preferred_time_label'],
                $draft['preferred_team_member_id'],
                $draft['preferred_team_member_name']
            );
            $draft['preferred_time'] = $incomingDraft['preferred_time'];
            if (isset($incomingDraft['preferred_date'])) {
                $draft['preferred_date'] = $incomingDraft['preferred_date'];
            }
            $persistedPublicContext['selected_time'] = $publicContext['selected_time'];
            unset($persistedPublicContext['selected_team_member_id']);
            $reservationContextChanged = true;
        }

        if (
            isset($incomingDraft['preferred_team_member_id'])
            && $this->contextValueChanged($previousContext, $publicContext, 'selected_team_member_id')
        ) {
            $draft['preferred_team_member_id'] = $incomingDraft['preferred_team_member_id'];
            $draft['preferred_team_member_name'] = $incomingDraft['preferred_team_member_name'];
            $persistedPublicContext['selected_team_member_id'] = $publicContext['selected_team_member_id'];
            $reservationContextChanged = true;
        }

        $previousContactContext = (array) data_get($metadata, 'public_contact_context', []);
        $publicContactContext = Arr::only($validated, [
            'visitor_name',
            'visitor_email',
            'visitor_phone',
        ]);
        $conversationUpdates = [];
        foreach ([
            'visitor_name' => 'contact_name',
            'visitor_email' => 'contact_email',
            'visitor_phone' => 'contact_phone',
        ] as $inputKey => $draftKey) {
            if (! array_key_exists($inputKey, $publicContactContext)) {
                continue;
            }

            $incomingValue = trim((string) ($publicContactContext[$inputKey] ?? ''));
            $previousValue = trim((string) ($previousContactContext[$inputKey] ?? ''));
            if ($incomingValue === '' || $incomingValue === $previousValue) {
                continue;
            }

            $draft[$draftKey] = $incomingValue;
            $conversationUpdates[$inputKey] = $incomingValue;
            $previousContactContext[$inputKey] = $incomingValue;
        }

        if ($reservationContextChanged) {
            unset($draft['proposed_slots'], $draft['selected_slot']);
            unset($metadata['booking_confirmation']);
        }

        $metadata['public_context'] = $persistedPublicContext;
        $metadata['public_contact_context'] = $previousContactContext;
        $metadata['reservation_draft'] = $draft;

        $conversation->update([
            ...$conversationUpdates,
            'metadata' => $metadata,
        ]);
        $conversation->refresh();
    }

    /**
     * @param  array<string, mixed>  $previousContext
     * @param  array<string, mixed>  $publicContext
     */
    private function contextValueChanged(array $previousContext, array $publicContext, string $key): bool
    {
        return (string) ($previousContext[$key] ?? '') !== (string) ($publicContext[$key] ?? '');
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

        if (preg_match('/\d{4}-\d{1,2}-\d{1,2}/', $value) === 1) {
            try {
                return Carbon::parse($value)->format('H:i');
            } catch (\Throwable) {
                return null;
            }
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

    /**
     * @return array<int, array{label: string, message: string, tone: string}>
     */
    private function quickReplies(AiConversation $conversation): array
    {
        if (in_array($conversation->status, [AiConversation::STATUS_RESOLVED, AiConversation::STATUS_ABANDONED], true)) {
            return [];
        }

        $confirmation = (array) data_get($conversation->metadata, 'booking_confirmation', []);
        if (
            (bool) ($confirmation['summary_shown'] ?? false)
            && (bool) ($confirmation['awaiting_user_confirmation'] ?? false)
            && ! (bool) ($confirmation['confirmed_by_user'] ?? false)
        ) {
            return [
                ['label' => 'Confirmer la demande', 'message' => 'oui', 'tone' => 'primary'],
                ['label' => 'Modifier', 'message' => 'modifier', 'tone' => 'secondary'],
            ];
        }

        $draft = (array) data_get($conversation->metadata, 'reservation_draft', []);
        if (! empty($draft['selected_slot'])) {
            return [];
        }

        return collect((array) ($draft['proposed_slots'] ?? []))
            ->take(3)
            ->map(function (array $slot): array {
                $index = (int) ($slot['index'] ?? 0);
                $date = trim((string) ($slot['date'] ?? ''));
                $time = trim((string) ($slot['time'] ?? ''));
                $member = trim((string) ($slot['team_member_name'] ?? ''));
                $when = trim($date.' '.$time);

                return [
                    'label' => trim("{$index} · {$when}".($member !== '' ? " · {$member}" : '')),
                    'message' => (string) $index,
                    'tone' => 'secondary',
                ];
            })
            ->filter(fn (array $reply): bool => $reply['message'] !== '0')
            ->values()
            ->all();
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
