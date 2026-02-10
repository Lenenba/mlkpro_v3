<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\ReservationSettingsRequest;
use App\Models\AvailabilityException;
use App\Models\Reservation;
use App\Models\ReservationResource;
use App\Models\ReservationSetting;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationNotificationPreferenceService;
use App\Support\ReservationPresetResolver;
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
        $resolvedAccountSettings = $this->availabilityService->resolveSettings($account->id, null);
        $teamSettings = ReservationSetting::query()
            ->forAccount($account->id)
            ->whereNotNull('team_member_id')
            ->get();
        $resources = ReservationResource::query()
            ->forAccount($account->id)
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id',
                'team_member_id',
                'name',
                'type',
                'capacity',
                'is_active',
                'metadata',
            ])
            ->map(fn (ReservationResource $resource) => [
                'id' => $resource->id,
                'team_member_id' => $resource->team_member_id,
                'name' => $resource->name,
                'type' => $resource->type,
                'capacity' => (int) $resource->capacity,
                'is_active' => (bool) $resource->is_active,
                'metadata' => $resource->metadata,
            ])
            ->values();

        return $this->inertiaOrJson('Settings/Reservations', [
            'timezone' => $this->availabilityService->timezoneForAccount($account),
            'teamMembers' => $teamMembers,
            'weeklyAvailabilities' => $weeklyAvailabilities,
            'exceptions' => $exceptions,
            'accountSettings' => $this->formatSettings($accountSettings, $resolvedAccountSettings),
            'notificationSettings' => $this->notificationPreferences->resolveFor($account),
            'teamSettings' => $teamSettings
                ->map(fn (ReservationSetting $item) => [
                    ...$this->formatSettings($item),
                    'team_member_id' => $item->team_member_id,
                ])
                ->values(),
            'resources' => $resources,
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

            if (array_key_exists('resources', $validated)) {
                $keepIds = [];
                foreach ($validated['resources'] ?? [] as $item) {
                    $payload = [
                        'account_id' => $account->id,
                        'team_member_id' => $item['team_member_id'] ?? null,
                        'name' => trim((string) ($item['name'] ?? '')),
                        'type' => trim((string) ($item['type'] ?? 'general')) ?: 'general',
                        'capacity' => max(1, (int) ($item['capacity'] ?? 1)),
                        'is_active' => (bool) ($item['is_active'] ?? true),
                        'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : null,
                    ];

                    if (!empty($item['id'])) {
                        $resource = ReservationResource::query()
                            ->forAccount($account->id)
                            ->whereKey($item['id'])
                            ->first();
                        if ($resource) {
                            $resource->update($payload);
                            $keepIds[] = $resource->id;
                            continue;
                        }
                    }

                    $created = ReservationResource::query()->create($payload);
                    $keepIds[] = $created->id;
                }

                ReservationResource::query()
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

        if ($teamMemberId === null) {
            $preset = ReservationPresetResolver::normalizePreset((string) ($data['business_preset'] ?? ''));
            $presetDefaults = ReservationPresetResolver::defaults($preset);

            $payload['business_preset'] = $preset;
            $payload['late_release_minutes'] = array_key_exists('late_release_minutes', $data)
                ? (int) $data['late_release_minutes']
                : (int) $presetDefaults['late_release_minutes'];
            $payload['waitlist_enabled'] = array_key_exists('waitlist_enabled', $data)
                ? (bool) $data['waitlist_enabled']
                : (bool) $presetDefaults['waitlist_enabled'];
            $payload['queue_mode_enabled'] = array_key_exists('queue_mode_enabled', $data)
                ? (bool) $data['queue_mode_enabled']
                : (bool) ($presetDefaults['queue_mode_enabled'] ?? false);
            $payload['queue_dispatch_mode'] = array_key_exists('queue_dispatch_mode', $data)
                ? (string) $data['queue_dispatch_mode']
                : (string) ($presetDefaults['queue_dispatch_mode'] ?? 'fifo_with_appointment_priority');
            $payload['queue_grace_minutes'] = array_key_exists('queue_grace_minutes', $data)
                ? max(1, min(60, (int) $data['queue_grace_minutes']))
                : max(1, min(60, (int) ($presetDefaults['queue_grace_minutes'] ?? 5)));
            $payload['queue_pre_call_threshold'] = array_key_exists('queue_pre_call_threshold', $data)
                ? max(1, min(20, (int) $data['queue_pre_call_threshold']))
                : max(1, min(20, (int) ($presetDefaults['queue_pre_call_threshold'] ?? 2)));
            $payload['queue_no_show_on_grace_expiry'] = array_key_exists('queue_no_show_on_grace_expiry', $data)
                ? (bool) $data['queue_no_show_on_grace_expiry']
                : (bool) ($presetDefaults['queue_no_show_on_grace_expiry'] ?? false);
            $payload['deposit_required'] = array_key_exists('deposit_required', $data)
                ? (bool) $data['deposit_required']
                : (bool) ($presetDefaults['deposit_required'] ?? false);
            $payload['deposit_amount'] = array_key_exists('deposit_amount', $data)
                ? max(0, round((float) $data['deposit_amount'], 2))
                : max(0, round((float) ($presetDefaults['deposit_amount'] ?? 0), 2));
            $payload['no_show_fee_enabled'] = array_key_exists('no_show_fee_enabled', $data)
                ? (bool) $data['no_show_fee_enabled']
                : (bool) ($presetDefaults['no_show_fee_enabled'] ?? false);
            $payload['no_show_fee_amount'] = array_key_exists('no_show_fee_amount', $data)
                ? max(0, round((float) $data['no_show_fee_amount'], 2))
                : max(0, round((float) ($presetDefaults['no_show_fee_amount'] ?? 0), 2));
        }

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

    private function formatSettings(?ReservationSetting $setting, ?array $defaults = null): array
    {
        $resolvedDefaults = $defaults ?? ReservationPresetResolver::defaults(
            ReservationPresetResolver::normalizePreset((string) ($setting?->business_preset ?? ''))
        );

        $preset = ReservationPresetResolver::normalizePreset((string) ($setting?->business_preset ?? $resolvedDefaults['business_preset'] ?? null));

        return [
            'business_preset' => $preset,
            'buffer_minutes' => (int) ($setting?->buffer_minutes ?? $resolvedDefaults['buffer_minutes'] ?? 0),
            'slot_interval_minutes' => (int) ($setting?->slot_interval_minutes ?? $resolvedDefaults['slot_interval_minutes'] ?? 30),
            'min_notice_minutes' => (int) ($setting?->min_notice_minutes ?? $resolvedDefaults['min_notice_minutes'] ?? 0),
            'max_advance_days' => (int) ($setting?->max_advance_days ?? $resolvedDefaults['max_advance_days'] ?? 90),
            'cancellation_cutoff_hours' => (int) ($setting?->cancellation_cutoff_hours ?? $resolvedDefaults['cancellation_cutoff_hours'] ?? 12),
            'allow_client_cancel' => (bool) ($setting?->allow_client_cancel ?? $resolvedDefaults['allow_client_cancel'] ?? true),
            'allow_client_reschedule' => (bool) ($setting?->allow_client_reschedule ?? $resolvedDefaults['allow_client_reschedule'] ?? true),
            'late_release_minutes' => (int) ($setting?->late_release_minutes ?? $resolvedDefaults['late_release_minutes'] ?? 0),
            'waitlist_enabled' => (bool) ($setting?->waitlist_enabled ?? $resolvedDefaults['waitlist_enabled'] ?? false),
            'queue_mode_enabled' => (bool) ($setting?->queue_mode_enabled ?? $resolvedDefaults['queue_mode_enabled'] ?? false),
            'queue_dispatch_mode' => (string) ($setting?->queue_dispatch_mode ?? $resolvedDefaults['queue_dispatch_mode'] ?? 'fifo_with_appointment_priority'),
            'queue_grace_minutes' => (int) ($setting?->queue_grace_minutes ?? $resolvedDefaults['queue_grace_minutes'] ?? 5),
            'queue_pre_call_threshold' => (int) ($setting?->queue_pre_call_threshold ?? $resolvedDefaults['queue_pre_call_threshold'] ?? 2),
            'queue_no_show_on_grace_expiry' => (bool) ($setting?->queue_no_show_on_grace_expiry ?? $resolvedDefaults['queue_no_show_on_grace_expiry'] ?? false),
            'deposit_required' => (bool) ($setting?->deposit_required ?? $resolvedDefaults['deposit_required'] ?? false),
            'deposit_amount' => (float) ($setting?->deposit_amount ?? $resolvedDefaults['deposit_amount'] ?? 0),
            'no_show_fee_enabled' => (bool) ($setting?->no_show_fee_enabled ?? $resolvedDefaults['no_show_fee_enabled'] ?? false),
            'no_show_fee_amount' => (float) ($setting?->no_show_fee_amount ?? $resolvedDefaults['no_show_fee_amount'] ?? 0),
        ];
    }
}
