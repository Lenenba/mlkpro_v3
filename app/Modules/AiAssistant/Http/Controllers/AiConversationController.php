<?php

namespace App\Modules\AiAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Requests\SendAiMessageRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AiConversationController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->resolveAccount($request);
        $this->authorize('viewAny', AiConversation::class);
        $filters = $request->only(['status', 'channel', 'intent', 'date']);
        $timezone = $account->company_timezone ?: config('app.timezone', 'UTC');

        $conversations = AiConversation::query()
            ->forTenant((int) $account->id)
            ->withCount(['messages', 'pendingActions'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['intent'] ?? null, fn ($query, $intent) => $query->where('intent', $intent))
            ->when($filters['date'] ?? null, function ($query, $date) use ($timezone) {
                $day = Carbon::parse((string) $date, $timezone);

                $query->whereBetween('created_at', [
                    $day->copy()->startOfDay()->utc(),
                    $day->copy()->endOfDay()->utc(),
                ]);
            })
            ->latest()
            ->paginate($this->resolveDataTablePerPage($request))
            ->withQueryString();

        return $this->inertiaOrJson('AiAssistant/Conversations/Index', [
            'filters' => $filters,
            'conversations' => $conversations->through(fn (AiConversation $conversation): array => $this->conversationRow($conversation)),
            'options' => [
                'statuses' => AiConversation::statuses(),
                'channels' => AiConversation::channels(),
            ],
        ]);
    }

    public function show(Request $request, AiConversation $conversation)
    {
        $this->authorize('view', $conversation);
        $conversation->load(['messages', 'actions', 'prospect:id,contact_name,contact_email,contact_phone', 'reservation.service:id,name']);

        return $this->inertiaOrJson('AiAssistant/Conversations/Show', [
            'conversation' => $this->conversationDetail($conversation),
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
    private function conversationRow(AiConversation $conversation): array
    {
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
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'pending_actions_count' => (int) ($conversation->pending_actions_count ?? 0),
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationDetail(AiConversation $conversation): array
    {
        return [
            ...$this->conversationRow($conversation),
            'summary' => $conversation->summary,
            'metadata' => $conversation->metadata,
            'prospect' => $conversation->prospect ? [
                'id' => (int) $conversation->prospect->id,
                'contact_name' => $conversation->prospect->contact_name,
                'contact_email' => $conversation->prospect->contact_email,
                'contact_phone' => $conversation->prospect->contact_phone,
            ] : null,
            'reservation' => $conversation->reservation ? [
                'id' => (int) $conversation->reservation->id,
                'status' => $conversation->reservation->status,
                'service_name' => $conversation->reservation->service?->name,
                'starts_at' => $conversation->reservation->starts_at?->toIso8601String(),
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
                'status' => (string) $action->status,
                'input_payload' => $action->input_payload,
                'output_payload' => $action->output_payload,
                'error_message' => $action->error_message,
                'executed_at' => $action->executed_at?->toIso8601String(),
                'created_at' => $action->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
