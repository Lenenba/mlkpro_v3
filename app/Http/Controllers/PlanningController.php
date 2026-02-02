<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Models\ShiftTemplate;
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
        $shiftTemplates = $this->loadShiftTemplates($owner->id);
        $defaultShiftTemplate = $this->defaultShiftTemplate();

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
            'shiftTemplates' => $shiftTemplates,
            'defaultShiftTemplate' => $defaultShiftTemplate,
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
            'break_minutes' => 'nullable|integer|min:0|max:60',
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
        $breakMinutesOverride = array_key_exists('break_minutes', $validated)
            ? (int) $validated['break_minutes']
            : null;

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
            $breakMinutesOverride = null;
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

        $teamMember = TeamMember::query()
            ->where('account_id', $owner->id)
            ->with('user')
            ->find($validated['team_member_id']);
        if (!$teamMember) {
            abort(404);
        }

        $addedMinutesByDate = [];
        $addedMinutesByWeek = [];
        foreach ($dates as $date) {
            $conflicts = $this->findShiftConflicts(
                $owner->id,
                $teamMember->id,
                $date,
                $startTime,
                $endTime,
                null
            );
            if ($conflicts->isNotEmpty()) {
                return response()->json([
                    'message' => $this->formatConflictMessage($conflicts->first(), $date),
                    'conflicts' => $this->formatConflictPayload($conflicts),
                ], 409);
            }

            if ($kind === 'shift') {
                $this->assertShiftRules(
                    $teamMember,
                    $owner->id,
                    $date,
                    $startTime,
                    $endTime,
                    null,
                    $addedMinutesByDate,
                    $addedMinutesByWeek,
                    $breakMinutesOverride
                );
            }
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
                'break_minutes' => $kind === 'shift' ? $breakMinutesOverride : null,
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

    public function update(Request $request, TeamMemberShift $shift)
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
        $canSelfManage = $membership
            && $shift->team_member_id === $membership->id
            && $shift->created_by_user_id === $user->id
            && in_array($shiftKind, ['absence', 'leave'], true);

        if ($shiftKind === 'shift' && !$canManage) {
            abort(403);
        }
        if ($shiftKind !== 'shift' && !$canApproveTimeOff && !$canSelfManage) {
            abort(403);
        }

        $validated = $request->validate([
            'shift_date' => 'required|date',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'break_minutes' => 'nullable|integer|min:0|max:60',
        ]);

        $shiftDate = Carbon::parse($validated['shift_date'])->startOfDay();
        $startTime = $this->parseTime($validated['start_time']);
        $endTime = $this->parseTime($validated['end_time']);
        if (!$startTime || !$endTime) {
            throw ValidationException::withMessages([
                'start_time' => ['Heure invalide.'],
            ]);
        }
        if ($endTime->lte($startTime)) {
            throw ValidationException::withMessages([
                'end_time' => ['L heure de fin doit etre apres l heure de debut.'],
            ]);
        }
        $breakMinutesOverride = array_key_exists('break_minutes', $validated)
            ? (int) $validated['break_minutes']
            : $shift->break_minutes;

        $teamMember = TeamMember::query()
            ->where('account_id', $owner->id)
            ->with('user')
            ->find($shift->team_member_id);
        if (!$teamMember) {
            abort(404);
        }

        $conflicts = $this->findShiftConflicts(
            $owner->id,
            $teamMember->id,
            $shiftDate,
            $startTime,
            $endTime,
            $shift->id
        );
        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'message' => $this->formatConflictMessage($conflicts->first(), $shiftDate),
                'conflicts' => $this->formatConflictPayload($conflicts),
            ], 409);
        }

        if ($shiftKind === 'shift') {
            $addedMinutesByDate = [];
            $addedMinutesByWeek = [];
            $this->assertShiftRules(
                $teamMember,
                $owner->id,
                $shiftDate,
                $startTime,
                $endTime,
                $shift->id,
                $addedMinutesByDate,
                $addedMinutesByWeek,
                $breakMinutesOverride
            );
        }

        $shift->shift_date = $shiftDate->toDateString();
        $shift->start_time = $startTime->format('H:i:s');
        $shift->end_time = $endTime->format('H:i:s');
        if ($shiftKind === 'shift' && array_key_exists('break_minutes', $validated)) {
            $shift->break_minutes = $breakMinutesOverride;
        }
        $shift->save();

        $shift->load('teamMember.user');

        return response()->json([
            'event' => $this->formatShiftEvents([$shift], $membership, $canManage, $canApproveTimeOff)[0] ?? null,
        ]);
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

    private function loadShiftTemplates(int $accountId): array
    {
        $templates = ShiftTemplate::query()
            ->where('is_active', true)
            ->where(function ($query) use ($accountId) {
                $query->whereNull('account_id')
                    ->orWhere('account_id', $accountId);
            })
            ->orderBy('position_title')
            ->get();

        $byPosition = [];
        foreach ($templates as $template) {
            $key = strtolower(trim((string) $template->position_title));
            if ($key === '') {
                continue;
            }
            if ($template->account_id === $accountId || !array_key_exists($key, $byPosition)) {
                $byPosition[$key] = $template;
            }
        }

        return collect($byPosition)
            ->values()
            ->map(fn (ShiftTemplate $template) => $this->formatShiftTemplate($template))
            ->values()
            ->all();
    }

    private function formatShiftTemplate(ShiftTemplate $template): array
    {
        $breaks = is_array($template->breaks) ? $template->breaks : [];
        $breakTotal = (int) ($template->break_minutes ?? 0);
        if ($breakTotal <= 0 && $breaks) {
            $breakTotal = array_sum($breaks);
        }

        return [
            'id' => $template->id,
            'position_title' => $template->position_title,
            'start_time' => substr((string) $template->start_time, 0, 5),
            'end_time' => substr((string) $template->end_time, 0, 5),
            'break_minutes' => $breakTotal,
            'breaks' => $breaks,
            'days_of_week' => $template->days_of_week ?? [],
        ];
    }

    private function defaultShiftTemplate(): array
    {
        return [
            'start_time' => '09:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'days_of_week' => [],
        ];
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

    private function findShiftConflicts(
        int $accountId,
        int $teamMemberId,
        Carbon $date,
        Carbon $startTime,
        Carbon $endTime,
        ?int $ignoreId
    ) {
        $start = $startTime->format('H:i:s');
        $end = $endTime->format('H:i:s');

        return TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->where('team_member_id', $teamMemberId)
            ->whereDate('shift_date', $date->toDateString())
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['approved', 'pending']);
            })
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->with('teamMember.user')
            ->orderBy('start_time')
            ->get();
    }

    private function formatConflictMessage(TeamMemberShift $conflict, Carbon $date): string
    {
        $memberName = $conflict->teamMember?->user?->name ?? 'Employe';
        $kind = $conflict->kind ?? 'shift';
        $kindLabel = match ($kind) {
            'leave' => 'conge',
            'absence' => 'absence',
            default => 'shift',
        };
        $start = substr((string) $conflict->start_time, 0, 5);
        $end = substr((string) $conflict->end_time, 0, 5);
        $day = $date->toDateString();

        return "Conflit: {$memberName} a deja un {$kindLabel} le {$day} de {$start} a {$end}.";
    }

    private function formatConflictPayload($conflicts): array
    {
        return collect($conflicts)->map(function (TeamMemberShift $shift) {
            return [
                'id' => $shift->id,
                'kind' => $shift->kind ?? 'shift',
                'status' => $shift->status ?? 'approved',
                'shift_date' => $shift->shift_date?->toDateString(),
                'start_time' => substr((string) $shift->start_time, 0, 5),
                'end_time' => substr((string) $shift->end_time, 0, 5),
                'title' => $shift->title,
                'member_name' => $shift->teamMember?->user?->name,
            ];
        })->values()->all();
    }

    private function assertShiftRules(
        TeamMember $member,
        int $accountId,
        Carbon $date,
        Carbon $startTime,
        Carbon $endTime,
        ?int $ignoreId,
        array &$addedMinutesByDate,
        array &$addedMinutesByWeek,
        ?int $breakMinutesOverride = null
    ): void {
        $rules = $this->resolvePlanningRules($member);
        if (!$rules) {
            return;
        }

        $ruleBreakMinutes = $rules['break_minutes'] ?? 0;
        $breakMinutes = $breakMinutesOverride !== null ? $breakMinutesOverride : $ruleBreakMinutes;
        $newMinutes = $this->calculateShiftMinutes($startTime, $endTime, (int) $breakMinutes);

        $minDay = $rules['min_hours_day'] ?? null;
        if ($minDay !== null && $newMinutes < (int) round($minDay * 60)) {
            throw ValidationException::withMessages([
                'start_time' => ["Le shift doit durer au moins {$minDay} heure(s)."],
            ]);
        }

        $dateKey = $date->toDateString();
        $existingDayMinutes = $this->sumShiftMinutesForDate(
            $accountId,
            $member->id,
            $date,
            $ruleBreakMinutes,
            $ignoreId
        );
        $dayTotal = $existingDayMinutes + ($addedMinutesByDate[$dateKey] ?? 0) + $newMinutes;
        $maxDay = $rules['max_hours_day'] ?? null;
        if ($maxDay !== null && $dayTotal > (int) round($maxDay * 60)) {
            throw ValidationException::withMessages([
                'shift_date' => ["Limite max/jour depassee ({$maxDay} heure(s))."],
            ]);
        }

        $weekKey = $date->copy()->startOfWeek()->toDateString();
        $existingWeekMinutes = $this->sumShiftMinutesForWeek(
            $accountId,
            $member->id,
            $date,
            $ruleBreakMinutes,
            $ignoreId
        );
        $weekTotal = $existingWeekMinutes + ($addedMinutesByWeek[$weekKey] ?? 0) + $newMinutes;
        $maxWeek = $rules['max_hours_week'] ?? null;
        if ($maxWeek !== null && $weekTotal > (int) round($maxWeek * 60)) {
            throw ValidationException::withMessages([
                'shift_date' => ["Limite max/semaine depassee ({$maxWeek} heure(s))."],
            ]);
        }

        $addedMinutesByDate[$dateKey] = ($addedMinutesByDate[$dateKey] ?? 0) + $newMinutes;
        $addedMinutesByWeek[$weekKey] = ($addedMinutesByWeek[$weekKey] ?? 0) + $newMinutes;
    }

    private function resolvePlanningRules(?TeamMember $member): array
    {
        $rules = $member?->planning_rules ?? [];
        if (!is_array($rules)) {
            return [];
        }

        return [
            'break_minutes' => isset($rules['break_minutes']) ? (int) $rules['break_minutes'] : 0,
            'min_hours_day' => isset($rules['min_hours_day']) ? (float) $rules['min_hours_day'] : null,
            'max_hours_day' => isset($rules['max_hours_day']) ? (float) $rules['max_hours_day'] : null,
            'max_hours_week' => isset($rules['max_hours_week']) ? (float) $rules['max_hours_week'] : null,
        ];
    }

    private function calculateShiftMinutes(Carbon $startTime, Carbon $endTime, int $breakMinutes = 0): int
    {
        if ($endTime->lte($startTime)) {
            return 0;
        }

        $minutes = $startTime->diffInMinutes($endTime);
        if ($breakMinutes > 0) {
            $minutes = max(0, $minutes - $breakMinutes);
        }

        return $minutes;
    }

    private function sumShiftMinutesForDate(
        int $accountId,
        int $teamMemberId,
        Carbon $date,
        int $fallbackBreakMinutes,
        ?int $ignoreId
    ): int {
        $shifts = TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->where('team_member_id', $teamMemberId)
            ->where('kind', 'shift')
            ->whereDate('shift_date', $date->toDateString())
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['approved', 'pending']);
            })
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get(['start_time', 'end_time', 'break_minutes']);

        return $shifts->sum(function (TeamMemberShift $shift) use ($fallbackBreakMinutes) {
            $start = $this->parseTime($shift->start_time);
            $end = $this->parseTime($shift->end_time);
            if (!$start || !$end) {
                return 0;
            }
            $breakMinutes = $shift->break_minutes;
            if ($breakMinutes === null) {
                $breakMinutes = $fallbackBreakMinutes;
            }
            return $this->calculateShiftMinutes($start, $end, (int) $breakMinutes);
        });
    }

    private function sumShiftMinutesForWeek(
        int $accountId,
        int $teamMemberId,
        Carbon $date,
        int $fallbackBreakMinutes,
        ?int $ignoreId
    ): int {
        $weekStart = $date->copy()->startOfWeek()->toDateString();
        $weekEnd = $date->copy()->endOfWeek()->toDateString();

        $shifts = TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->where('team_member_id', $teamMemberId)
            ->where('kind', 'shift')
            ->whereBetween('shift_date', [$weekStart, $weekEnd])
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['approved', 'pending']);
            })
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get(['start_time', 'end_time', 'break_minutes']);

        return $shifts->sum(function (TeamMemberShift $shift) use ($fallbackBreakMinutes) {
            $start = $this->parseTime($shift->start_time);
            $end = $this->parseTime($shift->end_time);
            if (!$start || !$end) {
                return 0;
            }
            $breakMinutes = $shift->break_minutes;
            if ($breakMinutes === null) {
                $breakMinutes = $fallbackBreakMinutes;
            }
            return $this->calculateShiftMinutes($start, $end, (int) $breakMinutes);
        });
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
