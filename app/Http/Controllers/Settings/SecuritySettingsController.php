<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;

class SecuritySettingsController extends Controller
{
    private const ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.password_changed',
        'auth.2fa.sent',
        'auth.2fa.resend',
    ];

    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->isClient()) {
            abort(403);
        }

        $ownerId = $user->accountOwnerId();
        $canViewTeam = $user->isAccountOwner();

        $userIds = $canViewTeam
            ? TeamMember::query()
                ->forAccount($ownerId)
                ->pluck('user_id')
                ->push($ownerId)
                ->filter()
                ->unique()
                ->values()
            : collect([$user->id]);

        $userMap = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'profile_picture'])
            ->keyBy('id');

        $userMorph = (new User())->getMorphClass();
        $activity = ActivityLog::query()
            ->where('subject_type', $userMorph)
            ->whereIn('subject_id', $userIds)
            ->whereIn('action', self::ACTIONS)
            ->latest()
            ->limit(50)
            ->get(['id', 'user_id', 'subject_id', 'action', 'properties', 'created_at'])
            ->map(function (ActivityLog $log) use ($userMap) {
                $subject = $userMap->get($log->subject_id);
                $actor = $userMap->get($log->user_id);
                $properties = (array) ($log->properties ?? []);

                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'ip' => $properties['ip'] ?? null,
                    'user_agent' => $properties['user_agent'] ?? null,
                    'channel' => $properties['channel'] ?? null,
                    'two_factor' => $properties['two_factor'] ?? null,
                    'device' => $properties['device'] ?? null,
                    'subject' => $subject ? [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'email' => $subject->email,
                        'profile_picture' => $subject->profile_picture,
                        'profile_picture_url' => $subject->profile_picture_url,
                    ] : null,
                    'actor' => $actor ? [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'email' => $actor->email,
                        'profile_picture' => $actor->profile_picture,
                        'profile_picture_url' => $actor->profile_picture_url,
                    ] : null,
                ];
            })
            ->values();

        return $this->inertiaOrJson('Settings/Security', [
            'two_factor' => [
                'required' => $user->requiresTwoFactor(),
                'enabled' => (bool) $user->two_factor_enabled,
                'email' => $user->email,
                'last_sent_at' => $user->two_factor_last_sent_at?->toIso8601String(),
            ],
            'rate_limit' => config('services.rate_limits.api_per_user'),
            'can_view_team' => $canViewTeam,
            'activity' => $activity,
        ]);
    }
}
