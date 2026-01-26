<?php

namespace App\Http\Controllers;

use App\Models\PlatformSupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends Controller
{
    private const STATUSES = ['open', 'pending', 'resolved', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const CATEGORIES = ['incident', 'bug', 'feature', 'other'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId();

        if (!$user || !$accountId) {
            abort(403);
        }

        $filters = $request->only(['search', 'status', 'priority']);

        $query = PlatformSupportTicket::query()
            ->where('account_id', $accountId)
            ->with('creator:id,name,email');

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
        $pendingCount = (clone $query)->where('status', 'pending')->count();
        $resolvedCount = (clone $query)->where('status', 'resolved')->count();
        $closedCount = (clone $query)->where('status', 'closed')->count();

        $tickets = $query->latest()->paginate(15)->withQueryString();

        return Inertia::render('Support/Index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'categories' => self::CATEGORIES,
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
        ]);

        PlatformSupportTicket::query()->create([
            'account_id' => $accountId,
            'created_by_user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => 'open',
            'priority' => $validated['priority'],
            'tags' => [$validated['category']],
        ]);

        return redirect()->back()->with('success', 'Support request submitted.');
    }
}
