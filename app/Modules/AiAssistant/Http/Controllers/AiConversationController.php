<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiAction;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Requests\SendAiMessageRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AiConversationController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('viewAny', AiConversation::class);
        $filters = $request->only(['status', 'channel', 'intent', 'date', 'queue', 'q']);
        $filters['q'] = is_string($filters['q'] ?? null)
            ? Str::limit(trim($filters['q']), 160, '')
            : '';
        $timezone = $account->company_timezone ?: config('app.timezone', 'UTC');

        $conversations = AiConversation::query()
            ->forTenant((int) $account->id)
            ->with([
                'pendingActions' => fn ($query) => $query
                    ->select(['id', 'tenant_id', 'conversation_id', 'action_type', 'status', 'input_payload', 'created_at'])
                    ->latest(),
                'reservation' => fn ($query) => $query
                    ->select(['id', 'account_id', 'service_id', 'status', 'starts_at'])
                    ->where('account_id', $account->id),
                'reservation.service' => fn ($query) => $query
                    ->select(['id', 'user_id', 'name'])
                    ->where('user_id', $account->id),
            ])
            ->withCount(['messages', 'pendingActions'])
            ->withMax('messages as last_message_at', 'created_at')
            ->withCasts(['last_message_at' => 'datetime'])
            ->when(($filters['queue'] ?? null) === 'review', fn ($query) => $this->applyNeedsReviewScope($query))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['intent'] ?? null, fn ($query, $intent) => $query->where('intent', $intent))
            ->when($filters['q'] !== '', fn (Builder $query) => $this->applySearchScope(
                $query,
                $filters['q'],
                (int) $account->id,
            ))
            ->when($filters['date'] ?? null, function ($query, $date) use ($timezone) {
                $day = Carbon::parse((string) $date, $timezone);

                $query->whereBetween('created_at', [
                    $day->copy()->startOfDay()->utc(),
                    $day->copy()->endOfDay()->utc(),
                ]);
            })
            ->orderByRaw('COALESCE(last_message_at, ai_conversations.created_at) DESC')
            ->orderByDesc('ai_conversations.id')
            ->paginate($this->resolveDataTablePerPage($request))
            ->withQueryString();

        return $this->inertiaOrJson('AiAssistant/Conversations/Index', [
            'filters' => $filters,
            'summary' => $this->conversationSummary((int) $account->id),
            'conversations' => $conversations->through(fn (AiConversation $conversation): array => $this->conversationRow($conversation, $timezone)),
            'options' => [
                'statuses' => AiConversation::statuses(),
                'channels' => AiConversation::channels(),
            ],
        ]);
    }

    public function show(Request $request, AiConversation $conversation)
    {
        $this->authorize('view', $conversation);
        $account = $this->resolveAccount($request);
        $timezone = $account->company_timezone ?: config('app.timezone', 'UTC');
        $conversation->load(['messages', 'actions', 'prospect:id,contact_name,contact_email,contact_phone', 'reservation.service:id,name']);

        return $this->inertiaOrJson('AiAssistant/Conversations/Show', [
            'conversation' => $this->conversationDetail($conversation, $timezone),
        ]);
    }

    public function reply(SendAiMessageRequest $request, AiConversation $conversation)
    {
        $this->authorize('reply', $conversation);

        $message = AiMessage::query()->create([
            'conversation_id' => (int) $conversation->id,
            'sender_type' => AiMessage::SENDER_HUMAN,
            'content' => (string) $request->validated('message'),
            'payload' => [
                'user_id' => (int) $request->user()->id,
            ],
        ]);

        $conversation->update([
            'status' => AiConversation::STATUS_WAITING_HUMAN,
        ]);

        return response()->json([
            'message' => 'Human reply saved.',
            'item' => [
                'id' => (int) $message->id,
                'sender_type' => (string) $message->sender_type,
                'content' => (string) $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    private function resolveAccount(Request $request): User
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $accountId = (int) $user->accountOwnerId();
        $account = $accountId === (int) $user->id
            ? $user
            : User::query()->find($accountId);

        if (! $account) {
            abort(404);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationRow(AiConversation $conversation, ?string $timezone = null): array
    {
        $lastMessageAt = $this->conversationLastMessageAt($conversation);

        return [
            'id' => (int) $conversation->id,
            'public_uuid' => (string) $conversation->public_uuid,
            'channel' => (string) $conversation->channel,
            'status' => (string) $conversation->status,
            'visitor_name' => $conversation->visitor_name,
            'visitor_email' => $conversation->visitor_email,
            'visitor_phone' => $conversation->visitor_phone,
            'intent' => $conversation->intent,
            'confidence_score' => $conversation->confidence_score !== null ? (float) $conversation->confidence_score : null,
            'title' => $this->conversationTitle($conversation),
            'short_summary' => $this->conversationShortSummary($conversation),
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'pending_actions_count' => (int) ($conversation->pending_actions_count ?? 0),
            'pending_actions' => $conversation->relationLoaded('pendingActions')
                ? $conversation->pendingActions
                    ->take(3)
                    ->map(fn (AiAction $action): array => $this->actionPreview($action, $timezone))
                    ->values()
                    ->all()
                : [],
            'reservation' => $this->reservationPreview($conversation),
            'last_message_at' => $lastMessageAt?->toIso8601String(),
            'last_activity_at' => ($lastMessageAt ?? $conversation->created_at)?->toIso8601String(),
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationDetail(AiConversation $conversation, ?string $timezone = null): array
    {
        return [
            ...$this->conversationRow($conversation, $timezone),
            'summary' => $conversation->summary,
            'metadata' => $conversation->metadata,
            'prospect' => $conversation->prospect ? [
                'id' => (int) $conversation->prospect->id,
                'contact_name' => $conversation->prospect->contact_name,
                'contact_email' => $conversation->prospect->contact_email,
                'contact_phone' => $conversation->prospect->contact_phone,
            ] : null,
            'messages' => $conversation->messages->map(fn (AiMessage $message): array => [
                'id' => (int) $message->id,
                'sender_type' => (string) $message->sender_type,
                'content' => (string) $message->content,
                'payload' => $message->payload,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
            'actions' => $conversation->actions->map(fn ($action): array => [
                'id' => (int) $action->id,
                'action_type' => (string) $action->action_type,
                'label' => $this->actionLabel((string) $action->action_type),
                'preview' => $this->actionPreviewText($action, $timezone),
                'status' => (string) $action->status,
                'input_payload' => $action->input_payload,
                'output_payload' => $action->output_payload,
                'error_message' => $action->error_message,
                'executed_at' => $action->executed_at?->toIso8601String(),
                'created_at' => $action->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function applyNeedsReviewScope(Builder $query): Builder
    {
        return $query->where(function ($query): void {
            $query
                ->where('status', AiConversation::STATUS_WAITING_HUMAN)
                ->orWhereHas('pendingActions');
        });
    }

    private function applySearchScope(Builder $query, string $search, int $tenantId): Builder
    {
        $like = '%'.$search.'%';
        $reservationId = $this->reservationIdFromSearch($search);

        return $query->where(function (Builder $query) use ($like, $reservationId, $tenantId): void {
            $query
                ->where('visitor_name', 'like', $like)
                ->orWhere('visitor_email', 'like', $like)
                ->orWhere('visitor_phone', 'like', $like)
                ->orWhere('public_uuid', 'like', $like)
                ->orWhereHas('messages', fn (Builder $messageQuery) => $messageQuery
                    ->where('content', 'like', $like))
                ->orWhereHas('reservation', fn (Builder $reservationQuery) => $reservationQuery
                    ->where('account_id', $tenantId)
                    ->whereHas('service', fn (Builder $serviceQuery) => $serviceQuery
                        ->where('user_id', $tenantId)
                        ->where('name', 'like', $like)));

            if ($reservationId !== null) {
                $query->orWhere('reservation_id', $reservationId);
            }
        });
    }

    private function reservationIdFromSearch(string $search): ?int
    {
        if (! preg_match('/^#?(\d+)$/', $search, $matches)) {
            return null;
        }

        $reservationId = (int) $matches[1];

        return $reservationId > 0 ? $reservationId : null;
    }

    private function conversationLastMessageAt(AiConversation $conversation): ?Carbon
    {
        $lastMessageAt = $conversation->getAttribute('last_message_at');

        if ($lastMessageAt === null && $conversation->relationLoaded('messages')) {
            $lastMessageAt = $conversation->messages->max('created_at');
        }

        return $lastMessageAt !== null ? Carbon::parse($lastMessageAt) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reservationPreview(AiConversation $conversation): ?array
    {
        if (! $conversation->relationLoaded('reservation') || ! $conversation->reservation) {
            return null;
        }

        return [
            'id' => (int) $conversation->reservation->id,
            'status' => (string) $conversation->reservation->status,
            'service_name' => $conversation->reservation->service?->name,
            'starts_at' => $conversation->reservation->starts_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function conversationSummary(int $tenantId): array
    {
        $baseQuery = AiConversation::query()->forTenant($tenantId);

        return [
            'total' => (clone $baseQuery)->count(),
            'needs_review' => $this->applyNeedsReviewScope((clone $baseQuery))->count(),
            'open' => (clone $baseQuery)->where('status', AiConversation::STATUS_OPEN)->count(),
            'resolved' => (clone $baseQuery)->where('status', AiConversation::STATUS_RESOLVED)->count(),
        ];
    }

    private function conversationTitle(AiConversation $conversation): string
    {
        $visitor = trim((string) ($conversation->visitor_name ?: ''));
        if ($visitor !== '') {
            return $visitor;
        }

        $contact = trim((string) ($conversation->visitor_email ?: $conversation->visitor_phone ?: ''));
        if ($contact !== '') {
            return $contact;
        }

        return 'Visiteur';
    }

    private function conversationShortSummary(AiConversation $conversation): string
    {
        $summary = trim((string) ($conversation->summary ?: ''));
        if ($summary !== '') {
            return str($summary)->limit(120)->toString();
        }

        $draftService = trim((string) data_get($conversation->metadata, 'reservation_draft.service_name', ''));
        if ($draftService !== '') {
            return 'Demande de reservation: '.$draftService;
        }

        if ($conversation->intent === AiConversation::INTENT_RESERVATION) {
            return 'Demande de reservation a verifier.';
        }

        return 'Conversation a consulter.';
    }

    /**
     * @return array<string, mixed>
     */
    private function actionPreview(AiAction $action, ?string $timezone = null): array
    {
        return [
            'id' => (int) $action->id,
            'action_type' => (string) $action->action_type,
            'label' => $this->actionLabel((string) $action->action_type),
            'preview' => $this->actionPreviewText($action, $timezone),
            'created_at' => $action->created_at?->toIso8601String(),
        ];
    }

    private function actionLabel(string $actionType): string
    {
        return match ($actionType) {
            AiAction::TYPE_CREATE_PROSPECT => 'Creer prospect',
            AiAction::TYPE_CREATE_CLIENT => 'Creer client',
            AiAction::TYPE_CREATE_RESERVATION => 'Creer reservation',
            AiAction::TYPE_RESCHEDULE_RESERVATION => 'Replanifier',
            AiAction::TYPE_CREATE_TASK => 'Creer tache',
            AiAction::TYPE_SEND_MESSAGE => 'Envoyer message',
            AiAction::TYPE_REQUEST_HUMAN_REVIEW => 'Avis humain',
            default => $actionType,
        };
    }

    private function actionPreviewText(AiAction $action, ?string $timezone = null): string
    {
        $payload = (array) ($action->input_payload ?? []);
        $parts = array_filter([
            trim((string) ($payload['contact_name'] ?? '')),
            trim((string) ($payload['service_name'] ?? '')),
            $this->formatActionDate($payload['starts_at'] ?? null, $timezone),
        ]);

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $reason = trim((string) ($payload['reason'] ?? ''));

        return $reason !== '' ? $reason : 'A verifier';
    }

    private function formatActionDate(mixed $value, ?string $timezone = null): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse((string) $value)
            ->timezone($timezone ?: config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i');
    }
}
