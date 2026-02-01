<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\Task;
use App\Models\User;
use App\Models\Work;
use App\Services\ShiftScheduleService;
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

        [$owner, $membership, $canManage, $isServiceCompany] = $this->resolvePlanningContext($user);

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

        if (!$canManage) {
            $memberPayload = $memberPayload
                ->filter(fn (array $member) => $member['user_id'] === $user->id)
                ->values();
        }

        $start = $request->query('start') ? Carbon::parse($request->query('start')) : now()->startOfWeek();
        $end = $request->query('end') ? Carbon::parse($request->query('end')) : now()->addWeeks(4)->endOfWeek();

        $events = $isServiceCompany
            ? $this->loadServiceEventsForRange($owner->id, $membership, $start, $end, $request)
            : $this->formatShiftEvents(
                $this->loadShiftsForRange($owner->id, $membership, $canManage, $start, $end, $request)
            );

        return $this->inertiaOrJson('Planning/Index', [
            'teamMembers' => $memberPayload->values(),
            'events' => $events,
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'canManage' => $isServiceCompany ? false : $canManage,
            'selfTeamMemberId' => $membership?->id,
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

        [$owner, $membership, $canManage, $isServiceCompany] = $this->resolvePlanningContext($user);

        $start = $request->query('start') ? Carbon::parse($request->query('start')) : now()->startOfWeek();
        $end = $request->query('end') ? Carbon::parse($request->query('end')) : now()->addWeeks(4)->endOfWeek();

        $events = $isServiceCompany
            ? $this->loadServiceEventsForRange($owner->id, $membership, $start, $end, $request)
            : $this->formatShiftEvents(
                $this->loadShiftsForRange($owner->id, $membership, $canManage, $start, $end, $request)
            );

        return response()->json([
            'events' => $events,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany] = $this->resolvePlanningContext($user);
        if ($isServiceCompany) {
            abort(403);
        }
        if (!$canManage) {
            abort(403);
        }

        $validated = $request->validate([
            'team_member_id' => [
                'required',
                'integer',
                Rule::exists('team_members', 'id')->where('account_id', $owner->id),
            ],
            'shift_date' => 'required|date',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'title' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:5000',
            'is_recurring' => 'nullable|boolean',
            'frequency' => 'nullable|string|in:daily,weekly,monthly,yearly',
            'repeats_on' => 'nullable|array',
            'repeats_on.*' => 'string|max:4',
            'recurrence_end_date' => 'nullable|date|after_or_equal:shift_date',
            'recurrence_count' => 'nullable|integer|min:1|max:365',
        ]);

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

        if (!$dates) {
            throw ValidationException::withMessages([
                'shift_date' => ['Aucune occurrence a creer.'],
            ]);
        }

        $groupId = $isRecurring && count($dates) > 1 ? Str::uuid()->toString() : null;
        $shiftIds = [];

        foreach ($dates as $date) {
            $shift = TeamMemberShift::create([
                'account_id' => $owner->id,
                'team_member_id' => (int) $validated['team_member_id'],
                'created_by_user_id' => $user->id,
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

        return response()->json([
            'events' => $this->formatShiftEvents($created),
            'created' => $created->count(),
        ], 201);
    }

    public function destroy(Request $request, TeamMemberShift $shift)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage, $isServiceCompany] = $this->resolvePlanningContext($user);
        if ($isServiceCompany) {
            abort(403);
        }
        if (!$canManage) {
            abort(403);
        }

        if ($shift->account_id !== $owner->id) {
            abort(404);
        }

        $shift->delete();

        return response()->json([
            'deleted' => true,
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
        if (!$canView) {
            abort(403);
        }

        return [$owner, $membership, $canManage, $isServiceCompany];
    }

    private function loadShiftsForRange(
        int $accountId,
        ?TeamMember $membership,
        bool $canManage,
        Carbon $start,
        Carbon $end,
        Request $request
    ) {
        $query = TeamMemberShift::query()
            ->where('account_id', $accountId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
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
            $query->where('team_member_id', $membership->id);
        }

        return $query->get();
    }

    private function formatShiftEvents($shifts): array
    {
        return collect($shifts)->map(function (TeamMemberShift $shift) {
            $memberName = $shift->teamMember?->user?->name ?? 'Member';
            $title = $shift->title ?: 'Shift';
            $date = $shift->shift_date ? $shift->shift_date->toDateString() : now()->toDateString();
            $start = $date . 'T' . substr((string) $shift->start_time, 0, 8);
            $end = $date . 'T' . substr((string) $shift->end_time, 0, 8);

            return [
                'id' => $shift->id,
                'title' => $memberName . ' · ' . $title,
                'start' => $start,
                'end' => $end,
                'allDay' => false,
                'extendedProps' => [
                    'team_member_id' => $shift->team_member_id,
                    'member_name' => $memberName,
                    'notes' => $shift->notes,
                    'recurrence_group_id' => $shift->recurrence_group_id,
                ],
            ];
        })->values()->all();
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
