<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Models\PlatformSupportTicket;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SupportController extends BaseController
{
    private const STATUSES = ['open', 'assigned', 'pending', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function index(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $filters = $request->only(['search', 'status', 'priority', 'account_id']);

        $query = PlatformSupportTicket::query()->with([
            'account:id,company_name,email',
            'creator:id,name,email',
            'assignedTo:id,name,email',
            'media',
        ]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhereHas('account', function ($accountQuery) use ($search) {
                        $accountQuery->where('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }

        $totalCount = (clone $query)->count();
        $stats = [
            'total' => $totalCount,
            'open' => (clone $query)->where('status', 'open')->count(),
            'assigned' => (clone $query)->where('status', 'assigned')->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'resolved' => (clone $query)->where('status', 'resolved')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
        ];

        $tickets = $query->latest()->limit(50)->get()->map(function (PlatformSupportTicket $ticket) {
            return [
                'id' => $ticket->id,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'account' => $ticket->account ? [
                    'id' => $ticket->account->id,
                    'company_name' => $ticket->account->company_name,
                    'email' => $ticket->account->email,
                ] : null,
                'creator' => $ticket->creator ? [
                    'id' => $ticket->creator->id,
                    'name' => $ticket->creator->name,
                    'email' => $ticket->creator->email,
                ] : null,
                'assigned_to' => $ticket->assignedTo ? [
                    'id' => $ticket->assignedTo->id,
                    'name' => $ticket->assignedTo->name,
                    'email' => $ticket->assignedTo->email,
                ] : null,
                'sla_due_at' => $ticket->sla_due_at?->toDateString(),
                'tags' => $ticket->tags ?? [],
                'media' => $ticket->media->map(fn ($media) => [
                    'id' => $media->id,
                    'url' => $media->url,
                    'original_name' => $media->original_name,
                    'mime' => $media->mime,
                    'size' => $media->size,
                ])->values(),
                'created_at' => $ticket->created_at,
            ];
        });

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $tenants = User::query()
            ->when($ownerRoleId, fn ($builder) => $builder->where('role_id', $ownerRoleId))
            ->orderBy('company_name')
            ->limit(100)
            ->get(['id', 'company_name', 'email']);

        return $this->jsonResponse([
            'tickets' => $tickets,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'tenants' => $tenants,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $validated = $request->validate([
            'account_id' => 'required|integer|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'priority' => ['required', 'string', Rule::in(self::PRIORITIES)],
            'sla_due_at' => 'nullable|date',
            'tags' => 'nullable|string|max:500',
        ]);

        $account = User::query()->findOrFail($validated['account_id']);
        if (!$account->isOwner()) {
            return $this->jsonResponse(['message' => 'Selected account is not a tenant owner.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ticket = PlatformSupportTicket::create([
            'account_id' => $account->id,
            'created_by_user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'sla_due_at' => $validated['sla_due_at'] ?? null,
            'tags' => $this->parseTags($validated['tags'] ?? ''),
        ]);

        $this->logAudit($request, 'support_ticket.created', $ticket);

        return $this->jsonResponse(['ticket_id' => $ticket->id], Response::HTTP_CREATED);
    }

    public function update(Request $request, PlatformSupportTicket $ticket)
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'priority' => ['required', 'string', Rule::in(self::PRIORITIES)],
            'sla_due_at' => 'nullable|date',
            'tags' => 'nullable|string|max:500',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $ticket->update([
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'sla_due_at' => $validated['sla_due_at'] ?? null,
            'tags' => $this->parseTags($validated['tags'] ?? ''),
            'title' => $validated['title'] ?? $ticket->title,
            'description' => $validated['description'] ?? $ticket->description,
        ]);

        $this->logAudit($request, 'support_ticket.updated', $ticket);

        return $this->jsonResponse(['message' => 'Support ticket updated.']);
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
