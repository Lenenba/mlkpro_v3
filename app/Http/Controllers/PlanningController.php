<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\TeamMemberShift;
use App\Models\User;
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

        [$owner, $membership, $canManage] = $this->resolvePlanningContext($user);

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

        $shifts = $this->loadShiftsForRange($owner->id, $membership, $canManage, $start, $end, $request);

        return $this->inertiaOrJson('Planning/Index', [
            'teamMembers' => $memberPayload->values(),
            'events' => $this->formatShiftEvents($shifts),
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'canManage' => $canManage,
            'selfTeamMemberId' => $membership?->id,
        ]);
    }

    public function events(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage] = $this->resolvePlanningContext($user);

        $start = $request->query('start') ? Carbon::parse($request->query('start')) : now()->startOfWeek();
        $end = $request->query('end') ? Carbon::parse($request->query('end')) : now()->addWeeks(4)->endOfWeek();

        $shifts = $this->loadShiftsForRange($owner->id, $membership, $canManage, $start, $end, $request);

        return response()->json([
            'events' => $this->formatShiftEvents($shifts),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$owner, $membership, $canManage] = $this->resolvePlanningContext($user);
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

        [$owner, $membership, $canManage] = $this->resolvePlanningContext($user);
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

        if (!$owner || $owner->company_type !== 'products' || !$owner->hasCompanyFeature('sales')) {
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

        $canManage = $user->id === $owner->id
            || ($membership?->hasPermission('sales.manage') ?? false);
        $canView = $canManage || ($membership?->hasPermission('sales.pos') ?? false);
        if (!$canView) {
            abort(403);
        }

        return [$owner, $membership, $canManage];
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
            $filterMember = $request->query('team_member_id');
            if ($filterMember) {
                $query->where('team_member_id', (int) $filterMember);
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
