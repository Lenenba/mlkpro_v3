<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Notifications\TimeOffRequestNotification;
use App\Services\ShiftScheduleService;
use App\Support\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanningController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany, $canApproveTimeOff] = $this->resolvePlanningContext($user);

        $teamMembers = TeamMember::query()
            ->where('account_id', $owner->id)
            ->where('is_active', true)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        $memberPayload = $teamMembers->map(fn (TeamMember $member) => [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->user?->name ?? 'Member',
            'role' => $member->role,
            'title' => $member->title,
        ]);

        if (!$canManage && !$canApproveTimeOff) {
            $memberPayload = $memberPayload
                ->filter(fn (array $member) => $member['user_id'] === $user->id)
                ->values();
        }

        $start = $request->query('start') ? Carbon::parse($request->query('start')) : now()->startOfWeek();
        $end = $request->query('end') ? Carbon::parse($request->query('end')) : now()->addWeeks(4)->endOfWeek();

        if ($isServiceCompany) {
            $serviceEvents = $this->loadServiceEventsForRange($owner->id, $membership, $start, $end, $request);
            $timeOffEvents = $this->formatShiftEvents(
                $this->loadTimeOffForRange($owner->id, $membership, $canApproveTimeOff, $start, $end, $request),
                $membership,
                $canManage,
                $canApproveTimeOff
            );
            $events = collect([...$serviceEvents, ...$timeOffEvents])
                ->sortBy('start')
                ->values()
                ->all();
        } else {
            $events = $this->formatShiftEvents(
                $this->loadShiftsForRange($owner->id, $membership, $canManage, $canApproveTimeOff, $start, $end, $request),
                $membership,
                $canManage,
                $canApproveTimeOff
            );
        }
        $pendingRequests = $canApproveTimeOff
            ? $this->formatShiftEvents(
                $this->loadPendingTimeOffRequests($owner->id, $membership, $request),
                $membership,
                $canManage,
                $canApproveTimeOff
            )
            : [];
        $timeOffSummary = $canApproveTimeOff
            ? $this->buildTimeOffSummary($owner->id, $membership, $canManage, $canApproveTimeOff, $request)
            : ['today' => [], 'week' => []];

        return $this->inertiaOrJson('Planning/Index', [
            'teamMembers' => $memberPayload->values(),
            'events' => $events,
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'canManage' => $isServiceCompany ? false : $canManage,
            'canApproveTimeOff' => $canApproveTimeOff,
            'selfTeamMemberId' => $membership?->id,
            'pendingRequests' => $pendingRequests,
            'timeOffSummary' => $timeOffSummary,
        ]);
    }

    public function calendar(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->resolvePlanningContext($user);

        return $this->inertiaOrJson('Planning/Calendar', []);
    }

    public function events(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany, $canApproveTimeOff] = $this->resolvePlanningContext($user);

        $start = $request->query('start') ? Carbon::parse($request->query('start')) : now()->startOfWeek();
        $end = $request->query('end') ? Carbon::parse($request->query('end')) : now()->addWeeks(4)->endOfWeek();

        if ($isServiceCompany) {
            $serviceEvents = $this->loadServiceEventsForRange($owner->id, $membership, $start, $end, $request);
            $timeOffEvents = $this->formatShiftEvents(
                $this->loadTimeOffForRange($owner->id, $membership, $canApproveTimeOff, $start, $end, $request),
                $membership,
                $canManage,
                $canApproveTimeOff
            );
            $events = collect([...$serviceEvents, ...$timeOffEvents])
                ->sortBy('start')
                ->values()
                ->all();
        } else {
            $events = $this->formatShiftEvents(
                $this->loadShiftsForRange($owner->id, $membership, $canManage, $canApproveTimeOff, $start, $end, $request),
                $membership,
                $canManage,
                $canApproveTimeOff
            );
        }
        $pendingRequests = $canApproveTimeOff
            ? $this->formatShiftEvents(
                $this->loadPendingTimeOffRequests($owner->id, $membership, $request),
                $membership,
                $canManage,
                $canApproveTimeOff
            )
            : [];
        $timeOffSummary = $canApproveTimeOff
            ? $this->buildTimeOffSummary($owner->id, $membership, $canManage, $canApproveTimeOff, $request)
            : ['today' => [], 'week' => []];

        return response()->json([
            'events' => $events,
            'pending_requests' => $pendingRequests,
            'time_off_summary' => $timeOffSummary,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany, $canApproveTimeOff] = $this->resolvePlanningContext($user);
        $validated = $request->validate([
            'kind' => 'nullable|string|in:shift,absence,leave',
            'status' => 'nullable|string|in:pending,approved',
            'team_member_id' => [
                'required',
                'integer',
                Rule::exists('team_members', 'id')->where('account_id', $owner->id),
            ],
            'shift_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:shift_date',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'title' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:5000',
            'is_recurring' => 'nullable|boolean',
            'frequency' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'repeats_on' => 'nullable|array',
            'repeats_on.*' => 'string|max:4',
            'recurrence_end_date' => 'nullable|date|after_or_equal:shift_date',
            'recurrence_count' => 'nullable|integer|min:1|max:365',
        ]);

        $kind = $validated['kind'] ?? 'shift';
        $isTimeOff = in_array($kind, ['absence', 'leave'], true);
        $status = $validated['status'] ?? ($isTimeOff && !$canApproveTimeOff ? 'pending' : 'approved');
        if (!$isTimeOff) {
            $status = 'approved';
        }
        if (!$canApproveTimeOff && $status !== 'pending') {
            $status = 'pending';
        }

        if ($isServiceCompany && $kind === 'shift') {
            abort(403);
        }

        if (!$canManage) {
            if (!$isTimeOff) {
                abort(403);
            }
            if (!$membership || (int) $validated['team_member_id'] !== $membership->id) {
                abort(403);
            }
        }

        if ($isTimeOff) {
            $startDate = Carbon::parse($validated['shift_date'])->startOfDay();
            $endDate = Carbon::parse($validated['end_date'] ?? $validated['shift_date'])->startOfDay();
            $daySpan = $startDate->diffInDays($endDate);
            if ($daySpan > 365) {
                throw ValidationException::withMessages([
                    'end_date' => ['La periode ne peut pas depasser 365 jours.'],
                ]);
            }

            $hasTimeRange = !empty($validated['start_time']) || !empty($validated['end_time']);
            if ($hasTimeRange) {
                if ($endDate->ne($startDate)) {
                    throw ValidationException::withMessages([
                        'end_date' => ['Les heures ne peuvent etre appliquees que sur une seule journee.'],
                    ]);
                }
                if (empty($validated['start_time']) || empty($validated['end_time'])) {
                    throw ValidationException::withMessages([
                        'start_time' => ['Heure requise.'],
                    ]);
                }

                $startTime = $this->parseTime($validated['start_time']);
                $endTime = $this->parseTime($validated['end_time']);
                if (!$startTime || !$endTime) {
                    throw ValidationException::withMessages([
                        'start_time' => ['Heure invalide.'],
                    ]);
                }
                if ($endTime->lt($startTime)) {
                    throw ValidationException::withMessages([
                        'end_time' => ['L heure de fin doit etre apres l heure de debut.'],
                    ]);
                }
            } else {
                $startTime = Carbon::createFromTime(0, 0, 0);
                $endTime = Carbon::createFromTime(23, 59, 0);
            }

            $dates = [];
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $dates[] = $date->copy();
            }

            $isRecurring = false;
        } else {
            if (empty($validated['start_time']) || empty($validated['end_time'])) {
                throw ValidationException::withMessages([
                    'start_time' => ['Heure requise.'],
                ]);
            }

            $startTime = $this->parseTime($validated['start_time']);
            $endTime = $this->parseTime($validated['end_time']);
            if (!$startTime || !$endTime) {
                throw ValidationException::withMessages([
                    'start_time' => ['Heure invalide.'],
                ]);
            }
            if ($endTime->lt($startTime)) {
                throw ValidationException::withMessages([
                    'end_time' => ['L heure de fin doit etre apres l heure de debut.'],
                ]);
            }

            $isRecurring = filter_var($validated['is_recurring'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isRecurring) {
                if (empty($validated['frequency'])) {
                    throw ValidationException::withMessages([
                        'frequency' => ['Frequence requise pour la recurrence.'],
                    ]);
                }
                if (empty($validated['recurrence_end_date']) && empty($validated['recurrence_count'])) {
                    throw ValidationException::withMessages([
                        'recurrence_end_date' => ['Date de fin requise pour la recurrence.'],
                    ]);
                }
            }

            $dates = $isRecurring
                ? app(ShiftScheduleService::class)->buildOccurrenceDates(
                    $validated['shift_date'],
                    $validated['recurrence_end_date'] ?? null,
                    $validated['frequency'] ?? null,
                    $validated['repeats_on'] ?? [],
                    $validated['recurrence_count'] ?? null
                )
                : [Carbon::parse($validated['shift_date'])->startOfDay()];
        }

        if (!$dates) {
            throw ValidationException::withMessages([
                'shift_date' => ['Aucune occurrence a creer.'],
            ]);
        }

        $groupId = $isRecurring && count($dates) > 1 ? Str::uuid()->toString() : null;
        if ($isTimeOff && count($dates) > 1) {
            $groupId = Str::uuid()->toString();
        }
        $shiftIds = [];

        foreach ($dates as $date) {
            $approvedByUserId = null;
            $approvedAt = null;
            if ($status === 'approved') {
                $approvedByUserId = $user->id;
                $approvedAt = now();
            }

            $shift = TeamMemberShift::create([
                'account_id' => $owner->id,
                'team_member_id' => (int) $validated['team_member_id'],
                'created_by_user_id' => $user->id,
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => $approvedAt,
                'kind' => $kind,
                'status' => $status,
                'title' => $validated['title'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'shift_date' => $date->toDateString(),
                'start_time' => $startTime->format('H:i:s'),
                'end_time' => $endTime->format('H:i:s'),
                'recurrence_group_id' => $groupId,
            ]);
            $shiftIds[] = $shift->id;
        }

        $created = TeamMemberShift::query()
            ->with('teamMember.user')
            ->whereIn('id', $shiftIds)
            ->get();

        if ($isTimeOff && $status === 'pending') {
            $this->notifyTimeOffRequest($owner, $created, $dates, $startTime, $endTime, $kind);
        }

        return response()->json([
            'events' => $this->formatShiftEvents($created, $membership, $canManage, $canApproveTimeOff),
            'created' => $created->count(),
        ], 201);
    }

    public function destroy(Request $request, TeamMemberShift $shift)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany, $canApproveTimeOff] = $this->resolvePlanningContext($user);

        if ($shift->account_id !== $owner->id) {
            abort(404);
        }

        $shiftKind = $shift->kind ?? 'shift';
        if (!$canManage) {
            $canSelfManage = $membership
                && $shift->team_member_id === $membership->id
                && $shift->created_by_user_id === $user->id
                && in_array($shiftKind, ['absence', 'leave'], true);
            if (!$canSelfManage) {
                abort(403);
            }
        }

        $shift->delete();

        return response()->json([
            'deleted' => true,
        ]);
    }

    public function updateStatus(Request $request, TeamMemberShift $shift)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany, $canApproveTimeOff] = $this->resolvePlanningContext($user);

        if ($shift->account_id !== $owner->id) {
            abort(404);
        }

        if (!$canApproveTimeOff) {
            abort(403);
        }

        $shiftKind = $shift->kind ?? 'shift';
        if (!in_array($shiftKind, ['absence', 'leave'], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:approved,rejected',
        ]);

        $shift->status = $validated['status'];
        $shift->approved_by_user_id = $user->id;
        $shift->approved_at = now();
        $shift->save();

        $shift->load('teamMember.user');

        return response()->json([
            'event' => $this->formatShiftEvents([$shift], $membership, $canManage, $canApproveTimeOff)[0] ?? null,
        ]);
    }

    private function resolvePlanningContext(User $user): array
    {
        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (!$owner) {
            abort(403);
        }

        $isServiceCompany = $owner->company_type !== 'products';
        if ($isServiceCompany) {
            if (!$owner->hasCompanyFeature('jobs') && !$owner->hasCompanyFeature('tasks')) {
                abort(403);
            }
        } elseif (!$owner->hasCompanyFeature('sales')) {
            abort(403);
        }

        $membership = null;
        if ($user->id !== $owner->id) {
            $membership = TeamMember::query()
                ->where('account_id', $owner->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (!$membership) {
                abort(403);
            }
        }

        if ($isServiceCompany) {
            $canManage = $user->id === $owner->id
                || ($membership?->hasPermission('jobs.edit') ?? false)
                || ($membership?->hasPermission('tasks.edit') ?? false);
            $canView = $canManage
                || ($membership?->hasPermission('jobs.view') ?? false)
                || ($membership?->hasPermission('tasks.view') ?? false);
        } else {
            $canManage = $user->id === $owner->id
                || ($membership?->hasPermission('sales.manage') ?? false);
            $canView = $canManage || ($membership?->hasPermission('sales.pos') ?? false);
        }
        $canApproveTimeOff = $user->id === $owner->id;
        if ($membership) {
            $isRoleApprover = in_array($membership->role, ['admin', 'sales_manager'], true);
            $hasManagePermission = $isServiceCompany
                ? ($membership->hasPermission('jobs.edit') || $membership->hasPermission('tasks.edit'))
                : $membership->hasPermission('sales.manage');
            $canApproveTimeOff = $canApproveTimeOff || $isRoleApprover || $hasManagePermission;
        }
        if (!$canView && $canApproveTimeOff) {
            $canView = true;
        }
        if (!$canView && $membership) {
            $canView = true;
        }
        if (!$canView) {
            abort(403);
        }

        return [$owner, $membership, $canManage, $isServiceCompany, $canApproveTimeOff];
    }

    private function loadShiftsForRange(
        int $accountId,
        ?TeamMember $membership,
        bool $canManage,
        bool $canApproveTimeOff,
        Carbon $start,
        Carbon $end,
        Request $request
    ) {
        $query = TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['approved', 'pending']);
            })
            ->with('teamMember.user')
            ->orderBy('shift_date')
            ->orderBy('start_time');

        if ($canManage) {
            $filterMembers = $request->query('team_member_ids');
            if (is_string($filterMembers)) {
                $filterMembers = array_filter(explode(',', $filterMembers));
            }
            if (is_array($filterMembers)) {
                $memberIds = collect($filterMembers)
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->values();
                if ($memberIds->isNotEmpty()) {
                    $query->whereIn('team_member_id', $memberIds);
                }
            } else {
                $filterMember = $request->query('team_member_id');
                if ($filterMember) {
                    $query->where('team_member_id', (int) $filterMember);
                }
            }
        } elseif ($membership) {
            if ($canApproveTimeOff) {
                $query->where(function ($query) use ($membership) {
                    $query->where('team_member_id', $membership->id)
                        ->orWhereIn('kind', ['absence', 'leave']);
                });
            } else {
                $query->where('team_member_id', $membership->id);
            }
        }

        return $query->get();
    }

    private function formatShiftEvents(
        $shifts,
        ?TeamMember $viewerMembership = null,
        bool $canManage = false,
        bool $canApproveTimeOff = false
    ): array
    {
        return collect($shifts)->map(function (TeamMemberShift $shift) use ($viewerMembership, $canManage, $canApproveTimeOff) {
            $memberName = $shift->teamMember?->user?->name ?? 'Member';
            $kind = $shift->kind ?: 'shift';
            $status = $shift->status ?: 'approved';
            $title = $shift->title ?: ucfirst($kind);
            $date = $shift->shift_date ? $shift->shift_date->toDateString() : now()->toDateString();
            $start = $date . 'T' . substr((string) $shift->start_time, 0, 8);
            $end = $date . 'T' . substr((string) $shift->end_time, 0, 8);
            $canSelfManage = $viewerMembership
                && $shift->team_member_id === $viewerMembership->id
                && $shift->created_by_user_id === $viewerMembership->user_id
                && in_array($kind, ['absence', 'leave'], true);
            $canApprove = $canApproveTimeOff
                && in_array($kind, ['absence', 'leave'], true)
                && $status === 'pending';

            return [
                'id' => $shift->id,
                'title' => $memberName . ' · ' . $title,
                'start' => $start,
                'end' => $end,
                'allDay' => $kind !== 'shift' && $this->isAllDayTimeOff($shift->start_time, $shift->end_time),
                'extendedProps' => [
                    'team_member_id' => $shift->team_member_id,
                    'member_name' => $memberName,
                    'notes' => $shift->notes,
                    'recurrence_group_id' => $shift->recurrence_group_id,
                    'kind' => $kind,
                    'status' => $status,
                    'can_delete' => $canManage || $canSelfManage,
                    'can_approve' => $canApprove,
                ],
            ];
        })->values()->all();
    }

    private function isAllDayTimeOff(?string $startTime, ?string $endTime): bool
    {
        $start = substr((string) $startTime, 0, 5);
        $end = substr((string) $endTime, 0, 5);

        return $start === '00:00' && $end === '23:59';
    }

    private function loadTimeOffForRange(
        int $accountId,
        ?TeamMember $membership,
        bool $canApproveTimeOff,
        Carbon $start,
        Carbon $end,
        Request $request
    ) {
        $query = TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('kind', ['absence', 'leave'])
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['approved', 'pending']);
            })
            ->with('teamMember.user')
            ->orderBy('shift_date')
            ->orderBy('start_time');

        if ($canApproveTimeOff) {
            $filterMembers = $request->query('team_member_ids');
            if (is_string($filterMembers)) {
                $filterMembers = array_filter(explode(',', $filterMembers));
            }
            if (is_array($filterMembers)) {
                $memberIds = collect($filterMembers)
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->values();
                if ($memberIds->isNotEmpty()) {
                    $query->whereIn('team_member_id', $memberIds);
                }
            } else {
                $filterMember = $request->query('team_member_id');
                if ($filterMember) {
                    $query->where('team_member_id', (int) $filterMember);
                }
            }
        } elseif ($membership) {
            $query->where('team_member_id', $membership->id);
        }

        return $query->get();
    }

    private function loadPendingTimeOffRequests(
        int $accountId,
        ?TeamMember $membership,
        Request $request
    ) {
        $query = TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->whereIn('kind', ['absence', 'leave'])
            ->where('status', 'pending')
            ->with('teamMember.user')
            ->orderBy('shift_date')
            ->orderBy('start_time')
            ->limit(100);

        $filterMembers = $request->query('team_member_ids');
        if (is_string($filterMembers)) {
            $filterMembers = array_filter(explode(',', $filterMembers));
        }
        if (is_array($filterMembers)) {
            $memberIds = collect($filterMembers)
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values();
            if ($memberIds->isNotEmpty()) {
                $query->whereIn('team_member_id', $memberIds);
            }
        } else {
            $filterMember = $request->query('team_member_id');
            if ($filterMember) {
                $query->where('team_member_id', (int) $filterMember);
            }
        }

        if ($membership && !$request->query('team_member_id') && !$request->query('team_member_ids')) {
            $query->whereNotNull('team_member_id');
        }

        return $query->get();
    }

    private function buildTimeOffSummary(
        int $accountId,
        ?TeamMember $membership,
        bool $canManage,
        bool $canApproveTimeOff,
        Request $request
    ): array {
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $todayKey = now()->toDateString();

        $weekShifts = $this->loadTimeOffForRange(
            $accountId,
            $membership,
            $canApproveTimeOff,
            $weekStart,
            $weekEnd,
            $request
        );

        $todayShifts = $weekShifts->filter(fn (TeamMemberShift $shift) => $shift->shift_date?->toDateString() === $todayKey);

        return [
            'today' => $this->formatShiftEvents($todayShifts, $membership, $canManage, $canApproveTimeOff),
            'week' => $this->formatShiftEvents($weekShifts, $membership, $canManage, $canApproveTimeOff),
        ];
    }

    private function loadServiceEventsForRange(
        int $accountId,
        ?TeamMember $membership,
        Carbon $start,
        Carbon $end,
        Request $request
    ): array {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $teamMemberIds = TeamMember::query()
            ->where('account_id', $accountId)
            ->pluck('id')
            ->all();

        $filterMembers = $request->query('team_member_ids');
        if (is_string($filterMembers)) {
            $filterMembers = array_filter(explode(',', $filterMembers));
        }
        $filterMemberIds = null;
        if (is_array($filterMembers) && $filterMembers) {
            $filterMemberIds = collect($filterMembers)->map(fn ($id) => (int) $id)->filter()->values()->all();
        } elseif ($request->query('team_member_id')) {
            $filterMemberIds = [(int) $request->query('team_member_id')];
        } elseif ($membership) {
            $filterMemberIds = [$membership->id];
        }

        if ($filterMemberIds) {
            $teamMemberIds = array_values(array_intersect($teamMemberIds, $filterMemberIds));
        }

        $works = Work::query()
            ->where('user_id', $accountId)
            ->whereBetween('start_date', [$startDate, $endDate])
            ->with(['teamMembers.user:id,name'])
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->get([
                'id',
                'job_title',
                'start_date',
                'start_time',
                'end_time',
                'is_all_day',
            ]);

        $tasks = Task::query()
            ->forAccount($accountId)
            ->whereBetween('due_date', [$startDate, $endDate])
            ->whereNull('work_id')
            ->with(['assignee.user:id,name'])
            ->orderBy('due_date')
            ->orderBy('start_time')
            ->get([
                'id',
                'title',
                'due_date',
                'start_time',
                'end_time',
                'assigned_team_member_id',
            ]);

        $events = collect();

        foreach ($works as $work) {
            $members = $work->teamMembers->isNotEmpty()
                ? $work->teamMembers
                : collect([null]);

            foreach ($members as $member) {
                $memberId = $member?->id;
                if ($filterMemberIds && !$memberId) {
                    continue;
                }
                if ($teamMemberIds && $memberId && !in_array($memberId, $teamMemberIds, true)) {
                    continue;
                }

                $memberName = $member?->user?->name ?? 'Team';
                $titleLabel = $work->job_title ?: 'Job';
                $date = $work->start_date ? $work->start_date->toDateString() : $startDate;
                $startTime = $work->start_time ?: ($work->is_all_day ? '00:00:00' : '09:00:00');
                $endTime = $work->end_time ?: ($work->is_all_day ? '23:59:00' : '10:00:00');

                $events->push([
                    'id' => 'work-' . $work->id . '-' . ($memberId ?: 'na'),
                    'title' => trim($memberName . ' · ' . $titleLabel),
                    'start' => $date . 'T' . substr((string) $startTime, 0, 8),
                    'end' => $date . 'T' . substr((string) $endTime, 0, 8),
                    'allDay' => (bool) $work->is_all_day,
                    'extendedProps' => [
                        'team_member_id' => $memberId,
                        'member_name' => $memberName,
                        'kind' => 'work',
                        'reference_id' => $work->id,
                    ],
                ]);
            }
        }

        foreach ($tasks as $task) {
            $memberId = $task->assigned_team_member_id;
            if ($filterMemberIds && !$memberId) {
                continue;
            }
            if ($teamMemberIds && $memberId && !in_array($memberId, $teamMemberIds, true)) {
                continue;
            }

            $memberName = $task->assignee?->user?->name ?? 'Team';
            $titleLabel = $task->title ?: 'Task';
            $date = $task->due_date ? $task->due_date->toDateString() : $startDate;
            $startTime = $task->start_time ?: '09:00:00';
            $endTime = $task->end_time ?: '10:00:00';

            $events->push([
                'id' => 'task-' . $task->id,
                'title' => trim($memberName . ' · ' . $titleLabel),
                'start' => $date . 'T' . substr((string) $startTime, 0, 8),
                'end' => $date . 'T' . substr((string) $endTime, 0, 8),
                'allDay' => false,
                'extendedProps' => [
                    'team_member_id' => $memberId,
                    'member_name' => $memberName,
                    'kind' => 'task',
                    'reference_id' => $task->id,
                ],
            ]);
        }

        return $events->values()->all();
    }

    private function notifyTimeOffRequest(
        User $owner,
        $created,
        array $dates,
        Carbon $startTime,
        Carbon $endTime,
        string $kind
    ): void {
        $first = $created->first();
        if (!$first) {
            return;
        }

        $memberName = $first->teamMember?->user?->name ?? 'Employe';
        $kindLabel = $kind === 'leave' ? 'congé' : 'absence';
        $startDate = $dates[0] ?? null;
        $endDate = $dates[count($dates) - 1] ?? null;
        $dateLabel = $startDate && $endDate && $startDate->ne($endDate)
            ? 'du ' . $startDate->toDateString() . ' au ' . $endDate->toDateString()
            : ($startDate?->toDateString() ?? '');

        $timeLabel = $this->isAllDayTimeOff($startTime->format('H:i:s'), $endTime->format('H:i:s'))
            ? null
            : $startTime->format('H:i') . ' - ' . $endTime->format('H:i');

        $title = $kind === 'leave'
            ? 'Nouvelle demande de congé'
            : 'Nouvelle demande d\'absence';

        $message = "{$memberName} a demandé un {$kindLabel} {$dateLabel}.";

        $actionUrl = route('planning.index');
        $details = [
            ['label' => 'Employé', 'value' => $memberName],
            ['label' => 'Type', 'value' => ucfirst($kindLabel)],
            ['label' => 'Dates', 'value' => $dateLabel ?: '-'],
        ];
        if ($timeLabel) {
            $details[] = ['label' => 'Heures', 'value' => $timeLabel];
        }
        if (!empty($first->notes)) {
            $details[] = ['label' => 'Notes', 'value' => $first->notes];
        }

        $approvers = $this->resolveTimeOffApprovers($owner);
        foreach ($approvers as $approver) {
            NotificationDispatcher::send($approver, new TimeOffRequestNotification(
                $title,
                $message,
                $actionUrl,
                [
                    'team_member_id' => $first->team_member_id,
                    'shift_ids' => $created->pluck('id')->values()->all(),
                    'kind' => $kind,
                    'status' => 'pending',
                ]
            ), [
                'team_member_id' => $first->team_member_id,
                'approver_id' => $approver->id ?? null,
            ]);
        }

        NotificationDispatcher::send($owner, new ActionEmailNotification(
            $title,
            'Une demande est en attente de validation.',
            $details,
            $actionUrl,
            'Voir le planning'
        ), [
            'team_member_id' => $first->team_member_id,
        ]);
    }

    private function resolveTimeOffApprovers(User $owner)
    {
        $isServiceCompany = $owner->company_type !== 'products';
        $approvers = collect([$owner]);

        $members = TeamMember::query()
            ->where('account_id', $owner->id)
            ->where('is_active', true)
            ->with('user')
            ->get();

        foreach ($members as $member) {
            if (!$member->user) {
                continue;
            }
            if ($member->user_id === $owner->id) {
                continue;
            }

            $isRoleApprover = in_array($member->role, ['admin', 'sales_manager'], true);
            $hasManagePermission = $isServiceCompany
                ? ($member->hasPermission('jobs.edit') || $member->hasPermission('tasks.edit'))
                : $member->hasPermission('sales.manage');

            if ($isRoleApprover || $hasManagePermission) {
                $approvers->push($member->user);
            }
        }

        return $approvers->unique('id')->values();
    }

    private function parseTime(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i', 'H:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable $exception) {
                continue;
            }
        }

        return null;
    }
}
