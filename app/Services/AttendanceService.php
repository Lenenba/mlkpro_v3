<?php

namespace App\Services;

use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function resolveAccountOwner(User $user): ?User
    {
        $ownerId = $user->accountOwnerId();
        if (! $ownerId) {
            return null;
        }

        return $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);
    }

    public function resolveTeamMembership(User $user, User $owner): ?TeamMember
    {
        if ($user->id === $owner->id) {
            return null;
        }

        return TeamMember::query()
            ->where('account_id', $owner->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    public function resolveSettings(User $owner): array
    {
        $settings = is_array($owner->company_time_settings) ? $owner->company_time_settings : [];
        $autoClockIn = array_key_exists('auto_clock_in', $settings) ? (bool) $settings['auto_clock_in'] : true;
        $autoClockOut = array_key_exists('auto_clock_out', $settings) ? (bool) $settings['auto_clock_out'] : true;
        $manualClock = array_key_exists('manual_clock', $settings) ? (bool) $settings['manual_clock'] : true;

        $isProductCompany = $owner->company_type === 'products';
        $enabled = $isProductCompany
            ? $owner->hasCompanyFeature('sales')
            : ($owner->hasCompanyFeature('jobs') || $owner->hasCompanyFeature('tasks') || $owner->hasCompanyFeature('reservations'));
        if (! $enabled) {
            return [
                'enabled' => false,
                'auto_clock_in' => false,
                'auto_clock_out' => false,
                'manual_clock' => false,
            ];
        }

        return [
            'enabled' => true,
            'auto_clock_in' => $autoClockIn,
            'auto_clock_out' => $autoClockOut,
            'manual_clock' => $manualClock,
        ];
    }

    public function autoClockIn(User $user): ?TeamMemberAttendance
    {
        if ($user->isClient()) {
            return null;
        }

        $owner = $this->resolveAccountOwner($user);
        if (! $owner) {
            return null;
        }

        $settings = $this->resolveSettings($owner);
        if (! $settings['auto_clock_in']) {
            return null;
        }

        $membership = $this->resolveTeamMembership($user, $owner);
        if ($user->id !== $owner->id && ! $membership) {
            return null;
        }

        return $this->clockIn($user, $membership, 'auto');
    }

    public function autoClockOut(User $user): ?TeamMemberAttendance
    {
        if ($user->isClient()) {
            return null;
        }

        $owner = $this->resolveAccountOwner($user);
        if (! $owner) {
            return null;
        }

        $settings = $this->resolveSettings($owner);
        if (! $settings['auto_clock_out']) {
            return null;
        }

        $membership = $this->resolveTeamMembership($user, $owner);
        if ($user->id !== $owner->id && ! $membership) {
            return null;
        }

        return $this->clockOut($user, $membership, 'auto');
    }

    public function clockIn(User $user, ?TeamMember $membership, string $method = 'manual'): TeamMemberAttendance
    {
        $accountId = $user->accountOwnerId();

        return DB::transaction(function () use ($accountId, $user, $membership, $method) {
            $openAttendance = TeamMemberAttendance::query()
                ->where('account_id', $accountId)
                ->where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->orderByDesc('clock_in_at')
                ->first();

            if ($openAttendance) {
                return $openAttendance;
            }

            return TeamMemberAttendance::create([
                'account_id' => $accountId,
                'user_id' => $user->id,
                'team_member_id' => $membership?->id,
                'clock_in_at' => now(),
                'method' => $method,
                'current_status' => TeamMemberAttendance::STATUS_AVAILABLE,
            ]);
        });
    }

    public function clockOut(User $user, ?TeamMember $membership, string $method = 'manual'): ?TeamMemberAttendance
    {
        $accountId = $user->accountOwnerId();

        return DB::transaction(function () use ($accountId, $user, $method, $membership) {
            $openAttendance = TeamMemberAttendance::query()
                ->where('account_id', $accountId)
                ->where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->orderByDesc('clock_in_at')
                ->first();

            if (! $openAttendance) {
                return null;
            }

            $openAttendance->update([
                'clock_out_at' => now(),
                'clock_out_method' => $method,
                'team_member_id' => $openAttendance->team_member_id ?? $membership?->id,
                'current_status' => TeamMemberAttendance::STATUS_OFFLINE,
            ]);

            return $openAttendance->fresh();
        });
    }

    public function setCurrentStatus(User $user, ?TeamMember $membership, string $status): TeamMemberAttendance
    {
        $status = strtolower(trim($status));
        if (! in_array($status, TeamMemberAttendance::CURRENT_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Unsupported attendance status.'],
            ]);
        }

        if ($status === TeamMemberAttendance::STATUS_OFFLINE) {
            $attendance = $this->clockOut($user, $membership);
            if (! $attendance) {
                throw ValidationException::withMessages([
                    'attendance' => ['No active attendance session found.'],
                ]);
            }

            return $attendance;
        }

        $accountId = $user->accountOwnerId();

        return DB::transaction(function () use ($accountId, $user, $membership, $status) {
            $openAttendance = TeamMemberAttendance::query()
                ->where('account_id', $accountId)
                ->where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->orderByDesc('clock_in_at')
                ->lockForUpdate()
                ->first();

            if (! $openAttendance) {
                throw ValidationException::withMessages([
                    'attendance' => ['You must be checked in before changing availability.'],
                ]);
            }

            $openAttendance->update([
                'team_member_id' => $openAttendance->team_member_id ?? $membership?->id,
                'current_status' => $status,
            ]);

            return $openAttendance->fresh();
        });
    }
}
