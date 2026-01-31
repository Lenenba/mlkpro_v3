<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PresenceController extends Controller
{
    public function index(Request $request)
    {
        [$user, $accountOwner, $settings, $membership] = $this->resolveContext($request);

        $canManage = $user->id === $accountOwner->id
            || ($membership?->hasPermission('sales.manage') ?? false);

        $teamMembers = TeamMember::query()
            ->where('account_id', $accountOwner->id)
            ->where('is_active', true)
            ->with('user')
            ->orderBy('created_at')
            ->get();

        $people = $this->buildPeoplePayload($accountOwner, $teamMembers, $canManage, $user);

        return $this->inertiaOrJson('Presence/Index', [
            'people' => $people->values(),
            'settings' => $settings,
            'permissions' => [
                'can_manage' => $canManage,
                'can_clock' => $settings['manual_clock'],
            ],
            'self_id' => $user->id,
            'company' => [
                'timezone' => $accountOwner->company_timezone,
            ],
        ]);
    }

    public function clockIn(Request $request)
    {
        [$user, $accountOwner, $settings, $membership] = $this->resolveContext($request);

        if (!$settings['manual_clock']) {
            return response()->json([
                'message' => 'Manual clocking is disabled.',
            ], 403);
        }

        $attendanceService = app(AttendanceService::class);
        $attendanceService->clockIn($user, $membership, 'manual');

        $person = $this->buildPersonPayload($user, $membership, $accountOwner->id);

        return response()->json([
            'person' => $person,
        ]);
    }

    public function clockOut(Request $request)
    {
        [$user, $accountOwner, $settings, $membership] = $this->resolveContext($request);

        if (!$settings['manual_clock']) {
            return response()->json([
                'message' => 'Manual clocking is disabled.',
            ], 403);
        }

        $attendanceService = app(AttendanceService::class);
        $attendanceService->clockOut($user, $membership, 'manual');

        $person = $this->buildPersonPayload($user, $membership, $accountOwner->id);

        return response()->json([
            'person' => $person,
        ]);
    }

    private function resolveContext(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $attendanceService = app(AttendanceService::class);
        $accountOwner = $attendanceService->resolveAccountOwner($user);
        if (!$accountOwner) {
            abort(403);
        }

        $settings = $attendanceService->resolveSettings($accountOwner);
        if (!$settings['enabled']) {
            abort(403);
        }

        $membership = $attendanceService->resolveTeamMembership($user, $accountOwner);
        if ($user->id !== $accountOwner->id && !$membership) {
            abort(403);
        }

        return [$user, $accountOwner, $settings, $membership];
    }

    private function buildPeoplePayload(User $owner, Collection $teamMembers, bool $canManage, User $viewer): Collection
    {
        $membersByUser = $teamMembers
            ->filter(fn (TeamMember $member) => $member->user)
            ->keyBy('user_id');

        $people = $teamMembers
            ->map(fn (TeamMember $member) => $member->user)
            ->filter()
            ->values();

        if (!$membersByUser->has($owner->id)) {
            $people = $people->prepend($owner);
        }

        if (!$canManage) {
            $people = $people->filter(fn (User $person) => $person->id === $viewer->id)->values();
        }

        if ($people->isEmpty()) {
            return collect();
        }

        $attendanceByUser = TeamMemberAttendance::query()
            ->where('account_id', $owner->id)
            ->whereIn('user_id', $people->pluck('id')->all())
            ->orderByDesc('clock_in_at')
            ->get()
            ->groupBy('user_id');

        return $people->map(function (User $person) use ($membersByUser, $attendanceByUser, $owner) {
            $member = $membersByUser->get($person->id);
            $entries = $attendanceByUser->get($person->id, collect());

            return $this->formatPerson($person, $member, $entries, $owner->id);
        });
    }

    private function buildPersonPayload(User $user, ?TeamMember $member, int $accountId): array
    {
        $entries = TeamMemberAttendance::query()
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->orderByDesc('clock_in_at')
            ->get();

        return $this->formatPerson($user, $member, $entries, $accountId);
    }

    private function formatPerson(User $user, ?TeamMember $member, Collection $entries, int $accountId): array
    {
        $current = $entries->firstWhere('clock_out_at', null) ?? $entries->first();
        $lastClockOut = $entries->first(fn ($entry) => $entry->clock_out_at !== null);
        $status = $current ? ($current->clock_out_at ? 'clocked_out' : 'clocked_in') : 'no_activity';

        $role = $member?->role;
        if (!$role && $user->id === $accountId) {
            $role = 'owner';
        }

        return [
            'id' => $user->id,
            'team_member_id' => $member?->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_picture_url' => $user->profile_picture_url,
            'role' => $role,
            'title' => $member?->title,
            'is_active' => $member?->is_active ?? true,
            'status' => $status,
            'clock_in_at' => $current?->clock_in_at?->toIso8601String(),
            'clock_out_at' => $current?->clock_out_at?->toIso8601String(),
            'last_clock_out_at' => $lastClockOut?->clock_out_at?->toIso8601String(),
            'method' => $current?->method,
            'clock_out_method' => $current?->clock_out_method,
        ];
    }
}
