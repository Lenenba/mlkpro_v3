<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PlatformSupportTicket;
use App\Models\PlatformSupportTicketMedia;
use App\Utils\FileHandler;
use App\Services\SupportAssignmentService;
use App\Services\SupportSettingsService;
use App\Services\SupportTicketNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;

class SupportTicketController extends Controller
{
    private const STATUSES = ['open', 'assigned', 'pending', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const CATEGORIES = ['incident', 'bug', 'feature', 'other'];

    public function __construct(
        private SupportAssignmentService $assignmentService,
        private SupportSettingsService $settingsService,
        private SupportTicketNotificationService $notificationService
    ) {
    }

    public function index(Request $request): Response|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId();

        if (!$user || !$accountId) {
            abort(403);
        }

        $filters = $request->only(['search', 'status', 'priority']);

        $query = PlatformSupportTicket::query()
            ->where('account_id', $accountId)
            ->with([
                'creator:id,name,email',
                'assignedTo:id,name,email',
                'media',
            ]);

        $query->when($filters['search'] ?? null, function ($builder, $search) {
            $builder->where(function ($sub) use ($search) {
                $sub->where('title', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        });

        $query->when($filters['status'] ?? null, function ($builder, $status) {
            if (!in_array($status, self::STATUSES, true)) {
                return;
            }
            $builder->where('status', $status);
        });

        $query->when($filters['priority'] ?? null, function ($builder, $priority) {
            if (!in_array($priority, self::PRIORITIES, true)) {
                return;
            }
            $builder->where('priority', $priority);
        });

        $totalCount = (clone $query)->count();
        $openCount = (clone $query)->where('status', 'open')->count();
        $assignedCount = (clone $query)->where('status', 'assigned')->count();
        $pendingCount = (clone $query)->where('status', 'pending')->count();
        $resolvedCount = (clone $query)->where('status', 'resolved')->count();
        $closedCount = (clone $query)->where('status', 'closed')->count();

        $tickets = $query->latest()->paginate(15)->withQueryString();

        $payload = [
            'tickets' => $tickets,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'categories' => self::CATEGORIES,
            'stats' => [
                'total' => $totalCount,
                'open' => $openCount,
                'assigned' => $assignedCount,
                'pending' => $pendingCount,
                'resolved' => $resolvedCount,
                'closed' => $closedCount,
            ],
        ];

        return $this->inertiaOrJson('Support/Index', $payload);
    }

    public function show(Request $request, PlatformSupportTicket $ticket): Response|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId();

        if (!$user || !$accountId || $ticket->account_id !== $accountId) {
            abort(403);
        }

        $ticket->load([
            'creator:id,name,email',
            'assignedTo:id,name,email',
            'media',
        ]);

        $messages = $ticket->messages()
            ->where('is_internal', false)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        $mediaIds = $messages->flatMap(fn ($message) => data_get($message, 'meta.media_ids', []))
            ->filter()
            ->unique()
            ->values();

        $mediaLookup = $mediaIds->isEmpty()
            ? collect()
            : PlatformSupportTicketMedia::query()
                ->where('ticket_id', $ticket->id)
                ->whereIn('id', $mediaIds)
                ->get()
                ->keyBy('id');

        $messages->transform(function ($message) use ($mediaLookup) {
            $ids = collect(data_get($message, 'meta.media_ids', []));
            $message->setAttribute('media', $ids->map(fn ($id) => $mediaLookup->get($id))->filter()->values());
            return $message;
        });

        $activity = ActivityLog::query()
            ->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)
            ->with('user:id,name,email')
            ->latest()
            ->limit(50)
            ->get();

        $payload = [
            'ticket' => $ticket,
            'messages' => $messages,
            'activity' => $activity,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
        ];

        return $this->inertiaOrJson('Support/Show', $payload);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId();

        if (!$user || !$accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'category' => 'required|string|in:' . implode(',', self::CATEGORIES),
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'priority' => 'required|string|in:' . implode(',', self::PRIORITIES),
            'attachments' => 'nullable|array|max:4',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10000',
        ]);

        $slaHours = $this->settingsService->slaHours($validated['priority']);
        $slaDueAt = $slaHours > 0 ? now()->addHours($slaHours) : null;
        $assignee = $this->settingsService->autoAssignEnabled()
            ? $this->assignmentService->nextAssignee()
            : null;

        $status = $assignee ? 'assigned' : 'open';

        $ticket = PlatformSupportTicket::query()->create([
            'account_id' => $accountId,
            'created_by_user_id' => $user->id,
            'assigned_to_user_id' => $assignee?->id,
            'assigned_by_user_id' => $assignee ? null : null,
            'assigned_at' => $assignee ? now() : null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'priority' => $validated['priority'],
            'sla_due_at' => $slaDueAt,
            'tags' => [$validated['category']],
        ]);

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments', []) as $file) {
                if (!$file instanceof UploadedFile) {
                    continue;
                }

                $mime = $file->getClientMimeType();
                $path = FileHandler::storeFile('support-media', $file);

                PlatformSupportTicketMedia::query()->create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                ]);
            }
        }

        ActivityLog::record($user, $ticket, 'support_ticket.created', [
            'priority' => $ticket->priority,
            'status' => $ticket->status,
        ]);

        if ($assignee) {
            ActivityLog::record($user, $ticket, 'support_ticket.assigned', [
                'assigned_to_user_id' => $assignee->id,
            ]);
            $this->notificationService->notifyAssignment($ticket->load('creator'), $assignee);
        }

        if ($this->shouldReturnJson($request)) {
            $ticket->load(['creator:id,name,email', 'assignedTo:id,name,email', 'media']);
            return response()->json([
                'message' => 'Support request submitted.',
                'ticket' => $ticket,
            ], 201);
        }

        return redirect()->back()->with('success', 'Support request submitted.');
    }

    public function update(Request $request, PlatformSupportTicket $ticket)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId();

        if (!$user || !$accountId || $ticket->account_id !== $accountId) {
            abort(403);
        }

        $editableStatuses = self::STATUSES;
        if ($ticket->status !== 'assigned') {
            $editableStatuses = array_values(array_filter(self::STATUSES, fn ($status) => $status !== 'assigned'));
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'priority' => 'required|string|in:' . implode(',', self::PRIORITIES),
            'status' => 'required|string|in:' . implode(',', $editableStatuses),
            'attachments' => 'nullable|array|max:4',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10000',
        ]);

        $originalStatus = $ticket->status;

        $ticket->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'],
            'status' => $validated['status'],
        ]);

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments', []) as $file) {
                if (!$file instanceof UploadedFile) {
                    continue;
                }

                $mime = $file->getClientMimeType();
                $path = FileHandler::storeFile('support-media', $file);

                PlatformSupportTicketMedia::query()->create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                ]);
            }
        }

        ActivityLog::record($user, $ticket, 'support_ticket.updated', [
            'priority' => $ticket->priority,
            'status' => $ticket->status,
        ]);

        if ($originalStatus !== $ticket->status) {
            ActivityLog::record($user, $ticket, 'support_ticket.status_changed', [
                'from' => $originalStatus,
                'to' => $ticket->status,
            ]);
        }

        if ($this->shouldReturnJson($request)) {
            $ticket->load(['creator:id,name,email', 'assignedTo:id,name,email', 'media']);
            return response()->json([
                'message' => 'Support request updated.',
                'ticket' => $ticket,
            ]);
        }

        return redirect()->back()->with('success', 'Support request updated.');
    }
}
