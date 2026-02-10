<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\ReservationSettingsRequest;
use App\Models\AvailabilityException;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationSettingsController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationPreferenceService $notificationPreferences
    ) {
    }

    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('manageSettings', Reservation::class);
        $account = $this->resolveAccount($user);

        $teamMembers = TeamMember::query()
            ->forAccount($account->id)
            ->active()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $member) => [
                'id' => $member->id,
                'name' => $member->user?->name ?? 'Member',
                'title' => $member->title,
            ])
            ->values();

        $weeklyAvailabilities = WeeklyAvailability::query()
            ->forAccount($account->id)
            ->orderBy('team_member_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get([
                'id',
                'team_member_id',
                'day_of_week',
                'start_time',
                'end_time',
                'is_active',
            ])
            ->map(fn (WeeklyAvailability $item) => [
                'id' => $item->id,
                'team_member_id' => $item->team_member_id,
                'day_of_week' => $item->day_of_week,
                'start_time' => substr((string) $item->start_time, 0, 5),
                'end_time' => substr((string) $item->end_time, 0, 5),
                'is_active' => (bool) $item->is_active,
            ])
            ->values();

        $exceptions = AvailabilityException::query()
            ->forAccount($account->id)
            ->orderByDesc('date')
            ->orderBy('start_time')
            ->get([
                'id',
                'team_member_id',
                'date',
                'start_time',
                'end_time',
                'type',
                'reason',
            ])
            ->map(fn (AvailabilityException $item) => [
                'id' => $item->id,
                'team_member_id' => $item->team_member_id,
                'date' => $item->date?->toDateString(),
                'start_time' => $item->start_time ? substr((string) $item->start_time, 0, 5) : null,
                'end_time' => $item->end_time ? substr((string) $item->end_time, 0, 5) : null,
                'type' => $item->type,
                'reason' => $item->reason,
            ])
            ->values();

        $accountSettings = ReservationSetting::query()
            ->forAccount($account->id)
            ->whereNull('team_member_id')
            ->first();
        $teamSettings = ReservationSetting::query()
            ->forAccount($account->id)
            ->whereNotNull('team_member_id')
            ->get();

        return $this->inertiaOrJson('Settings/Reservations', [
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'teamMembers' => $teamMembers,
            'weeklyAvailabilities' => $weeklyAvailabilities,
            'exceptions' => $exceptions,
            'accountSettings' => $this->formatSettings($accountSettings),
            'notificationSettings' => $this->notificationPreferences->resolveFor($account),
            'teamSettings' => $teamSettings
                ->map(fn (ReservationSetting $item) => [
                    ...$this->formatSettings($item),
                    'team_member_id' => $item->team_member_id,
                ])
                ->values(),
        ]);
    }

    public function update(ReservationSettingsRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $this->authorize('manageSettings', Reservation::class);
        $account = $this->resolveAccount($user);
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $account) {
            if (array_key_exists('account_settings', $validated)) {
                $this->upsertSettings($account->id, null, $validated['account_settings'] ?? []);
            }

            if (array_key_exists('team_settings', $validated)) {
                $teamItems = collect($validated['team_settings'] ?? []);
                $keepTeamIds = [];
                foreach ($teamItems as $item) {
                    $teamMemberId = (int) ($item['team_member_id'] ?? 0);
                    if (!$teamMemberId) {
                        continue;
                    }
                    $keepTeamIds[] = $teamMemberId;
                    $this->upsertSettings($account->id, $teamMemberId, $item);
                }

                ReservationSetting::query()
                    ->forAccount($account->id)
                    ->whereNotNull('team_member_id')
                    ->when($keepTeamIds, fn ($query) => $query->whereNotIn('team_member_id', $keepTeamIds))
                    ->delete();
            }

            if (array_key_exists('weekly_availabilities', $validated)) {
                WeeklyAvailability::query()->forAccount($account->id)->delete();
                foreach ($validated['weekly_availabilities'] ?? [] as $item) {
                    $start = $this->normalizeTime($item['start_time']);
                    $end = $this->normalizeTime($item['end_time']);
                    if ($end <= $start) {
                        throw ValidationException::withMessages([
                            'weekly_availabilities' => ['Availability end time must be after start time.'],
                        ]);
                    }

                    WeeklyAvailability::query()->create([
                        'account_id' => $account->id,
                        'team_member_id' => (int) $item['team_member_id'],
                        'day_of_week' => (int) $item['day_of_week'],
                        'start_time' => $start,
                        'end_time' => $end,
                        'is_active' => (bool) ($item['is_active'] ?? true),
                    ]);
                }
            }

            if (array_key_exists('exceptions', $validated)) {
                $keepIds = [];
                foreach ($validated['exceptions'] ?? [] as $item) {
                    $start = !empty($item['start_time']) ? $this->normalizeTime($item['start_time']) : null;
                    $end = !empty($item['end_time']) ? $this->normalizeTime($item['end_time']) : null;
                    if (($start && !$end) || (!$start && $end)) {
                        throw ValidationException::withMessages([
                            'exceptions' => ['Exception requires both start and end time when one is provided.'],
                        ]);
                    }
                    if ($start && $end && $end <= $start) {
                        throw ValidationException::withMessages([
                            'exceptions' => ['Exception end time must be after start time.'],
                        ]);
                    }

                    $payload = [
                        'account_id' => $account->id,
                        'team_member_id' => $item['team_member_id'] ?? null,
                        'date' => $item['date'],
                        'start_time' => $start,
                        'end_time' => $end,
                        'type' => $item['type'],
                        'reason' => $item['reason'] ?? null,
                    ];

                    if (!empty($item['id'])) {
                        $exception = AvailabilityException::query()
                            ->forAccount($account->id)
                            ->whereKey($item['id'])
                            ->first();
                        if ($exception) {
                            $exception->update($payload);
                            $keepIds[] = $exception->id;
                            continue;
                        }
                    }

                    $created = AvailabilityException::query()->create($payload);
                    $keepIds[] = $created->id;
                }

                AvailabilityException::query()
                    ->forAccount($account->id)
                    ->when($keepIds, fn ($query) => $query->whereNotIn('id', $keepIds))
                    ->delete();
            }

            if (array_key_exists('notification_settings', $validated)) {
                $account->update([
                    'company_notification_settings' => $this->notificationPreferences->mergeCompanySettings(
                        $account,
                        $validated['notification_settings'] ?? []
                    ),
                ]);
            }
        });

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Reservation settings saved.',
            ]);
        }

        return redirect()->route('settings.reservations.edit')->with('success', 'Reservation settings saved.');
    }

    private function resolveAccount(User $user): User
    {
        $accountId = $user->accountOwnerId();
        $account = $accountId === $user->id
            ? $user
            : User::query()->find($accountId);

        if (!$account) {
            abort(404);
        }

        return $account;
    }

    private function upsertSettings(int $accountId, ?int $teamMemberId, array $data): void
    {
        $attributes = [
            'account_id' => $accountId,
            'team_member_id' => $teamMemberId,
        ];

        $payload = [
            'buffer_minutes' => (int) ($data['buffer_minutes'] ?? 0),
            'slot_interval_minutes' => (int) ($data['slot_interval_minutes'] ?? 30),
            'min_notice_minutes' => (int) ($data['min_notice_minutes'] ?? 0),
            'max_advance_days' => (int) ($data['max_advance_days'] ?? 90),
            'cancellation_cutoff_hours' => (int) ($data['cancellation_cutoff_hours'] ?? 12),
            'allow_client_cancel' => (bool) ($data['allow_client_cancel'] ?? true),
            'allow_client_reschedule' => (bool) ($data['allow_client_reschedule'] ?? true),
        ];

        ReservationSetting::query()->updateOrCreate($attributes, $payload);
    }

    private function normalizeTime(string $value): string
    {
        $time = trim($value);
        if (strlen($time) === 5) {
            return $time . ':00';
        }
        return $time;
    }

    private function formatSettings(?ReservationSetting $setting): array
    {
        return [
            'buffer_minutes' => (int) ($setting?->buffer_minutes ?? 0),
            'slot_interval_minutes' => (int) ($setting?->slot_interval_minutes ?? 30),
            'min_notice_minutes' => (int) ($setting?->min_notice_minutes ?? 0),
            'max_advance_days' => (int) ($setting?->max_advance_days ?? 90),
            'cancellation_cutoff_hours' => (int) ($setting?->cancellation_cutoff_hours ?? 12),
            'allow_client_cancel' => (bool) ($setting?->allow_client_cancel ?? true),
            'allow_client_reschedule' => (bool) ($setting?->allow_client_reschedule ?? true),
        ];
    }
}
