<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\PlatformSupportTicket;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends BaseSuperAdminController
{
    private const STATUSES = ['open', 'pending', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_MANAGE);

        $filters = $request->only(['search', 'status', 'priority', 'account_id']);

        $query = PlatformSupportTicket::query()->with([
            'account:id,company_name,email',
            'creator:id,name,email',
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

        return Inertia::render('SuperAdmin/Support/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'tenants' => $tenants,
            'stats' => [
                'total' => $totalCount,
                'open' => $openCount,
                'pending' => $pendingCount,
                'resolved' => $resolvedCount,
                'closed' => $closedCount,
            ],
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
        ]);

        $account = User::query()->findOrFail($validated['account_id']);
        if (!$account->isOwner()) {
            return redirect()->back()->with('error', 'Selected account is not a tenant owner.');
        }

        $tags = $this->parseTags($validated['tags'] ?? '');

        $ticket = PlatformSupportTicket::query()->create([
            'account_id' => $account->id,
            'created_by_user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'sla_due_at' => $validated['sla_due_at'] ?? null,
            'tags' => $tags,
        ]);

        $this->logAudit($request, 'support_ticket.created', $ticket);

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
        ]);

        $ticket->update([
            'status' => $validated['status'],
            'priority' => $validated['priority'],
            'sla_due_at' => $validated['sla_due_at'] ?? null,
            'tags' => $this->parseTags($validated['tags'] ?? ''),
        ]);

        $this->logAudit($request, 'support_ticket.updated', $ticket);

        return redirect()->back()->with('success', 'Support ticket updated.');
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
