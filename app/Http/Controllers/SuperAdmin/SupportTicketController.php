<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\ActivityLog;
use App\Models\PlatformSupportTicket;
use App\Models\PlatformSupportTicketMedia;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use App\Services\SupportAssignmentService;
use App\Services\SupportSettingsService;
use App\Services\SupportTicketNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends BaseSuperAdminController
{
    private const STATUSES = ['open', 'assigned', 'pending', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(
        private SupportAssignmentService $assignmentService,
        private SupportSettingsService $settingsService,
        private SupportTicketNotificationService $notificationService
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $filters = $request->only(['search', 'status', 'priority', 'account_id']);

        $query = PlatformSupportTicket::query()->with([
            'account:id,company_name,email',
            'creator:id,name,email',
            'assignedTo:id,name,email',
            'media',
        ]);

        $query->when($filters['search'] ?? null, function ($builder, $search) {
            $builder->where(function ($sub) use ($search) {
                $sub->where('title', 'like', '%' . $search . '%')
                    ->orWhereHas('account', function ($accountQuery) use ($search) {
                        $accountQuery->where('company_name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        });

        $query->when($filters['status'] ?? null, function ($builder, $status) {
            $builder->where('status', $status);
        });

        $query->when($filters['priority'] ?? null, function ($builder, $priority) {
            $builder->where('priority', $priority);
        });

        $query->when($filters['account_id'] ?? null, function ($builder, $accountId) {
            $builder->where('account_id', $accountId);
        });

        $totalCount = (clone $query)->count();
        $openCount = (clone $query)->where('status', 'open')->count();
        $assignedCount = (clone $query)->where('status', 'assigned')->count();
        $pendingCount = (clone $query)->where('status', 'pending')->count();
        $resolvedCount = (clone $query)->where('status', 'resolved')->count();
        $closedCount = (clone $query)->where('status', 'closed')->count();

        $tickets = $query->latest()->paginate(15)->withQueryString();

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $tenants = User::query()
            ->where('role_id', $ownerRoleId)
            ->orderBy('company_name')
            ->limit(100)
            ->get(['id', 'company_name', 'email']);

        $assignees = $this->assignmentService->agents();

        return Inertia::render('SuperAdmin/Support/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'tenants' => $tenants,
            'assignees' => $assignees,
            'stats' => [
                'total' => $totalCount,
                'open' => $openCount,
                'assigned' => $assignedCount,
                'pending' => $pendingCount,
                'resolved' => $resolvedCount,
                'closed' => $closedCount,
            ],
        ]);
    }

    public function show(Request $request, PlatformSupportTicket $ticket): Response
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $ticket->load([
            'account:id,company_name,email',
            'creator:id,name,email',
            'assignedTo:id,name,email',
            'media',
        ]);

        $messages = $ticket->messages()
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
            ->limit(80)
            ->get();

        $assignees = $this->assignmentService->agents();

        return Inertia::render('SuperAdmin/Support/Show', [
            'ticket' => $ticket,
            'messages' => $messages,
            'activity' => $activity,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'assignees' => $assignees,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $validated = $request->validate([
            'account_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'status' => 'required|string|in:' . implode(',', self::STATUSES),
            'priority' => 'required|string|in:' . implode(',', self::PRIORITIES),
            'sla_due_at' => 'nullable|date',
            'tags' => 'nullable|string|max:500',
            'assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $account = User::query()->findOrFail($validated['account_id']);
        if (!$account->isOwner()) {
            return redirect()->back()->with('error', 'Selected account is not a tenant owner.');
        }

        $tags = $this->parseTags($validated['tags'] ?? '');

        $assignee = $this->resolveAssignee($validated['assigned_to_user_id'] ?? null);

        $status = $validated['status'];
        $assignedAt = null;
        $assignedBy = null;
        if ($assignee) {
            $status = 'assigned';
            $assignedAt = now();
            $assignedBy = $request->user()->id;
        }

        $slaDueAt = $validated['sla_due_at'] ?? null;
        if (!$slaDueAt) {
            $slaHours = $this->settingsService->slaHours($validated['priority']);
            $slaDueAt = $slaHours > 0 ? now()->addHours($slaHours) : null;
        }

        $ticket = PlatformSupportTicket::query()->create([
            'account_id' => $account->id,
            'created_by_user_id' => $request->user()->id,
            'assigned_to_user_id' => $assignee?->id,
            'assigned_by_user_id' => $assignedBy,
            'assigned_at' => $assignedAt,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $status,
            'priority' => $validated['priority'],
            'sla_due_at' => $slaDueAt,
            'tags' => $tags,
        ]);

        $this->logAudit($request, 'support_ticket.created', $ticket);
        ActivityLog::record($request->user(), $ticket, 'support_ticket.created', [
            'priority' => $ticket->priority,
            'status' => $ticket->status,
        ]);

        if ($assignee) {
            ActivityLog::record($request->user(), $ticket, 'support_ticket.assigned', [
                'assigned_to_user_id' => $assignee->id,
            ]);
            $this->notificationService->notifyAssignment($ticket->load('creator'), $assignee);
        }

        return redirect()->back()->with('success', 'Support ticket created.');
    }

    public function update(Request $request, PlatformSupportTicket $ticket): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', self::STATUSES),
            'priority' => 'required|string|in:' . implode(',', self::PRIORITIES),
            'sla_due_at' => 'nullable|date',
            'tags' => 'nullable|string|max:500',
            'assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $assignee = $this->resolveAssignee($validated['assigned_to_user_id'] ?? null);
        $assignmentChanged = array_key_exists('assigned_to_user_id', $validated)
            && $assignee?->id !== $ticket->assigned_to_user_id;

        $status = $validated['status'];
        $assignedAt = $ticket->assigned_at;
        $assignedBy = $ticket->assigned_by_user_id;

        if ($assignmentChanged) {
            if ($assignee) {
                $status = in_array($status, ['resolved', 'closed'], true) ? $status : 'assigned';
                $assignedAt = now();
                $assignedBy = $request->user()->id;
            } else {
                $assignedAt = null;
                $assignedBy = null;
                if ($ticket->status === 'assigned') {
                    $status = 'open';
                }
            }
        }

        $originalStatus = $ticket->status;
        $ticket->update([
            'status' => $status,
            'priority' => $validated['priority'],
            'sla_due_at' => $validated['sla_due_at'] ?? null,
            'tags' => $this->parseTags($validated['tags'] ?? ''),
            'assigned_to_user_id' => $assignee?->id,
            'assigned_by_user_id' => $assignedBy,
            'assigned_at' => $assignedAt,
        ]);

        $this->logAudit($request, 'support_ticket.updated', $ticket);

        ActivityLog::record($request->user(), $ticket, 'support_ticket.updated', [
            'priority' => $ticket->priority,
            'status' => $ticket->status,
        ]);

        if ($assignmentChanged && $assignee) {
            ActivityLog::record($request->user(), $ticket, 'support_ticket.assigned', [
                'assigned_to_user_id' => $assignee->id,
            ]);
            $this->notificationService->notifyAssignment($ticket->load('creator'), $assignee);
        }

        if ($originalStatus !== $ticket->status) {
            ActivityLog::record($request->user(), $ticket, 'support_ticket.status_changed', [
                'from' => $originalStatus,
                'to' => $ticket->status,
            ]);
        }

        return redirect()->back()->with('success', 'Support ticket updated.');
    }

    private function resolveAssignee(?int $userId): ?User
    {
        if (!$userId) {
            return null;
        }

        $user = User::query()
            ->where('id', $userId)
            ->with(['role', 'platformAdmin'])
            ->first();

        if (!$user) {
            return null;
        }

        if (!$user->hasPlatformPermission(PlatformPermissions::SUPPORT_MANAGE)) {
            return null;
        }

        return $user;
    }

    private function parseTags(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $tags = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_unique($tags));
    }
}
