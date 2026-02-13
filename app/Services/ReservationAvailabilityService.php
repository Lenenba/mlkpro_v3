<?php

namespace App\Services;

use App\Models\AvailabilityException;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use App\Models\ReservationSetting;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Support\ReservationPresetResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationAvailabilityService
{
    private const MAX_BUFFER_MINUTES = 240;

    public function resolveAccountForUser(User $user): ?User
    {
        if ($user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();
            if ($customer && $customer->user_id) {
                return User::query()->find($customer->user_id);
            }
        }

        $accountId = $user->accountOwnerId();
        if (!$accountId) {
            return null;
        }

        return $accountId === $user->id
            ? $user
            : User::query()->find($accountId);
    }

    public function timezoneForAccount(?User $account): string
    {
        if (!$account) {
            return config('app.timezone', 'UTC');
        }

        return $account->company_timezone ?: config('app.timezone', 'UTC');
    }

    public function resolveSettings(int $accountId, ?int $teamMemberId = null): array
    {
        $account = User::query()
            ->select(['id', 'company_sector'])
            ->find($accountId);

        $accountLevel = ReservationSetting::query()
            ->forAccount($accountId)
            ->whereNull('team_member_id')
            ->first();

        $teamLevel = null;
        if ($teamMemberId) {
            $teamLevel = ReservationSetting::query()
                ->forAccount($accountId)
                ->where('team_member_id', $teamMemberId)
                ->first();
        }

        $resolvedPreset = ReservationPresetResolver::resolveForAccount(
            $account,
            $accountLevel?->business_preset
        );
        $defaults = ReservationPresetResolver::defaults($resolvedPreset);
        $queueFeaturesEnabled = ReservationPresetResolver::queueFeaturesEnabled($resolvedPreset);

        return [
            'business_preset' => $resolvedPreset,
            'buffer_minutes' => (int) ($teamLevel?->buffer_minutes ?? $accountLevel?->buffer_minutes ?? $defaults['buffer_minutes']),
            'slot_interval_minutes' => (int) ($teamLevel?->slot_interval_minutes ?? $accountLevel?->slot_interval_minutes ?? $defaults['slot_interval_minutes']),
            'min_notice_minutes' => (int) ($teamLevel?->min_notice_minutes ?? $accountLevel?->min_notice_minutes ?? $defaults['min_notice_minutes']),
            'max_advance_days' => (int) ($teamLevel?->max_advance_days ?? $accountLevel?->max_advance_days ?? $defaults['max_advance_days']),
            'cancellation_cutoff_hours' => (int) ($teamLevel?->cancellation_cutoff_hours ?? $accountLevel?->cancellation_cutoff_hours ?? $defaults['cancellation_cutoff_hours']),
            'allow_client_cancel' => (bool) ($teamLevel?->allow_client_cancel ?? $accountLevel?->allow_client_cancel ?? $defaults['allow_client_cancel']),
            'allow_client_reschedule' => (bool) ($teamLevel?->allow_client_reschedule ?? $accountLevel?->allow_client_reschedule ?? $defaults['allow_client_reschedule']),
            'late_release_minutes' => (int) ($accountLevel?->late_release_minutes ?? $defaults['late_release_minutes']),
            'waitlist_enabled' => (bool) ($accountLevel?->waitlist_enabled ?? $defaults['waitlist_enabled']),
            'queue_mode_enabled' => $queueFeaturesEnabled
                && (bool) ($accountLevel?->queue_mode_enabled ?? $defaults['queue_mode_enabled'] ?? false),
            'queue_assignment_mode' => in_array(
                (string) ($accountLevel?->queue_assignment_mode ?? $defaults['queue_assignment_mode'] ?? 'per_staff'),
                ['per_staff', 'global_pull'],
                true
            )
                ? (string) ($accountLevel?->queue_assignment_mode ?? $defaults['queue_assignment_mode'] ?? 'per_staff')
                : 'per_staff',
            'queue_dispatch_mode' => (string) ($accountLevel?->queue_dispatch_mode ?? $defaults['queue_dispatch_mode'] ?? 'fifo_with_appointment_priority'),
            'queue_grace_minutes' => max(1, min(60, (int) ($accountLevel?->queue_grace_minutes ?? $defaults['queue_grace_minutes'] ?? 5))),
            'queue_pre_call_threshold' => max(1, min(20, (int) ($accountLevel?->queue_pre_call_threshold ?? $defaults['queue_pre_call_threshold'] ?? 2))),
            'queue_no_show_on_grace_expiry' => (bool) ($accountLevel?->queue_no_show_on_grace_expiry ?? $defaults['queue_no_show_on_grace_expiry'] ?? false),
            'deposit_required' => (bool) ($accountLevel?->deposit_required ?? $defaults['deposit_required'] ?? false),
            'deposit_amount' => max(0, round((float) ($accountLevel?->deposit_amount ?? $defaults['deposit_amount'] ?? 0), 2)),
            'no_show_fee_enabled' => (bool) ($accountLevel?->no_show_fee_enabled ?? $defaults['no_show_fee_enabled'] ?? false),
            'no_show_fee_amount' => max(0, round((float) ($accountLevel?->no_show_fee_amount ?? $defaults['no_show_fee_amount'] ?? 0), 2)),
        ];
    }

    public function resolveDurationMinutes(int $accountId, ?int $serviceId, ?int $durationMinutes): int
    {
        if ($durationMinutes && $durationMinutes > 0) {
            return (int) $durationMinutes;
        }

        if ($serviceId) {
            $service = Product::query()
                ->services()
                ->where('user_id', $accountId)
                ->whereKey($serviceId)
                ->first();
            if ($service) {
                return 60;
            }
        }

        return 60;
    }

    public function generateSlots(
        int $accountId,
        Carbon $rangeStartUtc,
        Carbon $rangeEndUtc,
        int $durationMinutes,
        ?int $teamMemberId = null,
        ?int $partySize = null,
        ?array $resourceFilters = null
    ): array {
        $account = User::query()->find($accountId);
        if (!$account) {
            return ['timezone' => config('app.timezone', 'UTC'), 'slots' => []];
        }

        $timezone = $this->timezoneForAccount($account);
        $startUtc = $rangeStartUtc->copy()->utc();
        $endUtc = $rangeEndUtc->copy()->utc();
        if ($endUtc->lte($startUtc) || $durationMinutes <= 0) {
            return ['timezone' => $timezone, 'slots' => []];
        }

        $startLocal = $startUtc->copy()->setTimezone($timezone);
        $endLocal = $endUtc->copy()->setTimezone($timezone);

        $memberQuery = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user:id,name');
        if ($teamMemberId) {
            $memberQuery->whereKey($teamMemberId);
        }
        $members = $memberQuery->get();

        if ($members->isEmpty()) {
            return ['timezone' => $timezone, 'slots' => []];
        }

        $memberIds = $members->pluck('id')->all();

        $weekly = WeeklyAvailability::query()
            ->forAccount($accountId)
            ->whereIn('team_member_id', $memberIds)
            ->active()
            ->orderBy('start_time')
            ->get()
            ->groupBy('team_member_id');

        $exceptions = AvailabilityException::query()
            ->forAccount($accountId)
            ->whereDate('date', '>=', $startLocal->toDateString())
            ->whereDate('date', '<=', $endLocal->toDateString())
            ->where(function ($query) use ($memberIds) {
                $query->whereNull('team_member_id')
                    ->orWhereIn('team_member_id', $memberIds);
            })
            ->get();

        $reservations = Reservation::query()
            ->forAccount($accountId)
            ->whereIn('team_member_id', $memberIds)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $endUtc->copy()->addMinutes(self::MAX_BUFFER_MINUTES))
            ->where('ends_at', '>', $startUtc->copy()->subMinutes(self::MAX_BUFFER_MINUTES))
            ->orderBy('starts_at')
            ->get()
            ->groupBy('team_member_id');

        $normalizedResourceFilters = $this->normalizeResourceFilters($resourceFilters);
        $activeResources = ReservationResource::query()
            ->forAccount($accountId)
            ->active()
            ->get();
        $resourcesByMember = $activeResources->groupBy(function (ReservationResource $resource) {
            return $resource->team_member_id ? (string) $resource->team_member_id : 'global';
        });
        $resourceAllocations = $this->loadResourceAllocations(
            $accountId,
            $startUtc->copy()->subMinutes(self::MAX_BUFFER_MINUTES),
            $endUtc->copy()->addMinutes(self::MAX_BUFFER_MINUTES)
        );
        $shouldApplyResourceCapacity = (
            ($partySize && $partySize > 0)
            || !empty($normalizedResourceFilters['types'])
            || !empty($normalizedResourceFilters['resource_ids'])
        ) && $activeResources->isNotEmpty();

        $slots = [];
        $nowLocal = now($timezone);
        $dates = $this->dateRange($startLocal->copy()->startOfDay(), $endLocal->copy()->startOfDay());

        foreach ($members as $member) {
            $settings = $this->resolveSettings($accountId, $member->id);
            $buffer = max(0, min(self::MAX_BUFFER_MINUTES, (int) $settings['buffer_minutes']));
            $intervalMinutes = max(5, min(120, (int) $settings['slot_interval_minutes']));
            $memberWeekly = $weekly->get($member->id, collect());
            $memberReservations = $reservations->get($member->id, collect());

            foreach ($dates as $date) {
                $dayIntervals = $this->buildDayIntervals(
                    $member->id,
                    $date,
                    $memberWeekly,
                    $exceptions,
                    $timezone
                );
                if (!$dayIntervals) {
                    continue;
                }

                foreach ($dayIntervals as $interval) {
                    $cursor = $this->alignToInterval($interval['start']->copy(), $intervalMinutes);
                    while ($cursor->copy()->addMinutes($durationMinutes)->lte($interval['end'])) {
                        $slotStart = $cursor->copy();
                        $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);

                        if ($slotStart->lt($startLocal) || $slotEnd->gt($endLocal)) {
                            $cursor->addMinutes($intervalMinutes);
                            continue;
                        }

                        if (!$this->passesNoticeRules($slotStart, $nowLocal, $settings)) {
                            $cursor->addMinutes($intervalMinutes);
                            continue;
                        }

                        if ($this->hasReservationConflict($slotStart, $slotEnd, $memberReservations, $buffer, $timezone)) {
                            $cursor->addMinutes($intervalMinutes);
                            continue;
                        }

                        $selectedResource = null;
                        if ($shouldApplyResourceCapacity) {
                            $selectedResource = $this->pickAvailableResourceForWindow(
                                $member->id,
                                $slotStart->copy()->utc(),
                                $slotEnd->copy()->utc(),
                                $resourcesByMember,
                                $resourceAllocations,
                                $partySize,
                                $normalizedResourceFilters
                            );

                            if (!$selectedResource) {
                                $cursor->addMinutes($intervalMinutes);
                                continue;
                            }
                        }

                        $slots[] = [
                            'team_member_id' => $member->id,
                            'team_member_name' => $member->user?->name ?? 'Member',
                            'starts_at' => $slotStart->copy()->utc()->toIso8601String(),
                            'ends_at' => $slotEnd->copy()->utc()->toIso8601String(),
                            'label' => $slotStart->format('D, M j - H:i'),
                            'date' => $slotStart->toDateString(),
                            'time' => $slotStart->format('H:i'),
                            'resource_id' => $selectedResource?->id,
                            'resource_name' => $selectedResource?->name,
                            'resource_type' => $selectedResource?->type,
                            'resource_capacity' => $selectedResource?->capacity,
                        ];

                        $cursor->addMinutes($intervalMinutes);
                    }
                }
            }
        }

        usort($slots, function (array $left, array $right) {
            $leftKey = $left['starts_at'] . ':' . $left['team_member_id'];
            $rightKey = $right['starts_at'] . ':' . $right['team_member_id'];
            return strcmp($leftKey, $rightKey);
        });

        return [
            'timezone' => $timezone,
            'slots' => $slots,
        ];
    }

    public function book(array $payload, User $actor): Reservation
    {
        $accountId = (int) $payload['account_id'];
        $teamMemberId = (int) $payload['team_member_id'];
        $account = User::query()->findOrFail($accountId);
        $timezone = $this->timezoneForAccount($account);

        $durationMinutes = $this->resolveDurationMinutes(
            $accountId,
            isset($payload['service_id']) ? (int) $payload['service_id'] : null,
            isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : null
        );

        $startUtc = $this->parseToUtc((string) $payload['starts_at'], $payload['timezone'] ?? $timezone);
        $endUtc = !empty($payload['ends_at'])
            ? $this->parseToUtc((string) $payload['ends_at'], $payload['timezone'] ?? $timezone)
            : $startUtc->copy()->addMinutes($durationMinutes);

        if ($endUtc->lte($startUtc)) {
            throw ValidationException::withMessages([
                'starts_at' => ['The end time must be after the start time.'],
            ]);
        }

        $durationMinutes = $startUtc->diffInMinutes($endUtc);
        $partySize = $this->normalizePartySize($payload['party_size'] ?? null);
        $resourceFilters = $this->normalizeResourceFilters($payload['resource_filters'] ?? null);
        $requestedResourceIds = $this->normalizeResourceIds($payload['resource_ids'] ?? []);
        $baseMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return DB::transaction(function () use (
            $payload,
            $accountId,
            $teamMemberId,
            $actor,
            $timezone,
            $startUtc,
            $endUtc,
            $durationMinutes,
            $partySize,
            $resourceFilters,
            $requestedResourceIds,
            $baseMetadata
        ) {
            $teamMember = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->whereKey($teamMemberId)
                ->lockForUpdate()
                ->first();
            if (!$teamMember) {
                throw ValidationException::withMessages([
                    'team_member_id' => ['Selected team member is not available.'],
                ]);
            }

            $settings = $this->resolveSettings($accountId, $teamMemberId);
            $bufferMinutes = max(0, min(
                self::MAX_BUFFER_MINUTES,
                (int) ($payload['buffer_minutes'] ?? $settings['buffer_minutes'])
            ));

            $this->assertWithinAvailability($accountId, $teamMemberId, $startUtc, $endUtc, $timezone);
            $this->assertNoDoubleBooking($accountId, $teamMemberId, $startUtc, $endUtc, $bufferMinutes, null);

            $resourceIds = $requestedResourceIds;
            if (
                empty($resourceIds)
                && $this->hasResourceConstraint($partySize, $resourceFilters)
            ) {
                $autoResource = $this->pickAvailableResourceForReservation(
                    $accountId,
                    $teamMemberId,
                    $startUtc,
                    $endUtc,
                    $partySize,
                    $resourceFilters,
                    null
                );

                if (!$autoResource) {
                    throw ValidationException::withMessages([
                        'starts_at' => ['Selected slot does not have enough resource capacity.'],
                    ]);
                }

                $resourceIds = [$autoResource->id];
            }

            $this->assertResourcesAvailable(
                $accountId,
                $teamMemberId,
                $startUtc,
                $endUtc,
                $resourceIds,
                $partySize,
                null
            );

            $metadata = $this->mergeReservationMetadata(
                $baseMetadata,
                $partySize,
                $resourceFilters,
                $resourceIds,
                $settings
            );

            $reservation = Reservation::query()->create([
                'account_id' => $accountId,
                'team_member_id' => $teamMemberId,
                'client_id' => $payload['client_id'] ?? null,
                'client_user_id' => $payload['client_user_id'] ?? null,
                'service_id' => $payload['service_id'] ?? null,
                'created_by_user_id' => $actor->id,
                'status' => $payload['status'] ?? Reservation::STATUS_PENDING,
                'source' => $payload['source'] ?? Reservation::SOURCE_STAFF,
                'timezone' => $timezone,
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => $bufferMinutes,
                'internal_notes' => $payload['internal_notes'] ?? null,
                'client_notes' => $payload['client_notes'] ?? null,
                'metadata' => $metadata ?: null,
            ]);

            $this->syncResourceAllocations($reservation, $resourceIds);

            return $reservation;
        });
    }

    public function reschedule(
        Reservation $reservation,
        array $payload,
        User $actor
    ): Reservation {
        $accountId = (int) $reservation->account_id;
        $account = User::query()->findOrFail($accountId);
        $timezone = $this->timezoneForAccount($account);
        $newTeamMemberId = isset($payload['team_member_id'])
            ? (int) $payload['team_member_id']
            : (int) $reservation->team_member_id;

        $durationMinutes = $this->resolveDurationMinutes(
            $accountId,
            isset($payload['service_id']) ? (int) $payload['service_id'] : (int) $reservation->service_id,
            isset($payload['duration_minutes']) ? (int) $payload['duration_minutes'] : (int) $reservation->duration_minutes
        );

        $startUtc = $this->parseToUtc((string) $payload['starts_at'], $payload['timezone'] ?? $timezone);
        $endUtc = !empty($payload['ends_at'])
            ? $this->parseToUtc((string) $payload['ends_at'], $payload['timezone'] ?? $timezone)
            : $startUtc->copy()->addMinutes($durationMinutes);

        if ($endUtc->lte($startUtc)) {
            throw ValidationException::withMessages([
                'starts_at' => ['The end time must be after the start time.'],
            ]);
        }

        $durationMinutes = $startUtc->diffInMinutes($endUtc);
        $resourceIdsProvided = array_key_exists('resource_ids', $payload);
        $resourceFiltersProvided = array_key_exists('resource_filters', $payload);
        $partySizeProvided = array_key_exists('party_size', $payload);
        $requestedResourceIds = $resourceIdsProvided
            ? $this->normalizeResourceIds($payload['resource_ids'] ?? [])
            : [];
        $requestedResourceFilters = $resourceFiltersProvided
            ? $this->normalizeResourceFilters($payload['resource_filters'] ?? null)
            : null;
        $requestedPartySize = $partySizeProvided
            ? $this->normalizePartySize($payload['party_size'] ?? null)
            : null;

        return DB::transaction(function () use (
            $reservation,
            $payload,
            $actor,
            $accountId,
            $newTeamMemberId,
            $timezone,
            $startUtc,
            $endUtc,
            $durationMinutes,
            $resourceIdsProvided,
            $resourceFiltersProvided,
            $partySizeProvided,
            $requestedResourceIds,
            $requestedResourceFilters,
            $requestedPartySize
        ) {
            $teamMember = TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->whereKey($newTeamMemberId)
                ->lockForUpdate()
                ->first();
            if (!$teamMember) {
                throw ValidationException::withMessages([
                    'team_member_id' => ['Selected team member is not available.'],
                ]);
            }

            $settings = $this->resolveSettings($accountId, $newTeamMemberId);
            $bufferMinutes = max(0, min(
                self::MAX_BUFFER_MINUTES,
                (int) ($payload['buffer_minutes'] ?? $reservation->buffer_minutes ?? $settings['buffer_minutes'])
            ));

            $this->assertWithinAvailability($accountId, $newTeamMemberId, $startUtc, $endUtc, $timezone);
            $this->assertNoDoubleBooking($accountId, $newTeamMemberId, $startUtc, $endUtc, $bufferMinutes, $reservation->id);

            $existingMetadata = is_array($reservation->metadata) ? $reservation->metadata : [];
            $currentResourceIds = ReservationResourceAllocation::query()
                ->forAccount($accountId)
                ->where('reservation_id', $reservation->id)
                ->pluck('reservation_resource_id')
                ->map(fn ($value) => (int) $value)
                ->values()
                ->all();

            $effectivePartySize = $partySizeProvided
                ? $requestedPartySize
                : $this->normalizePartySize($existingMetadata['party_size'] ?? null);
            $effectiveResourceFilters = $resourceFiltersProvided
                ? ($requestedResourceFilters ?? $this->normalizeResourceFilters(null))
                : $this->normalizeResourceFilters($existingMetadata['resource_filters'] ?? null);
            $resourceIds = $resourceIdsProvided ? $requestedResourceIds : $currentResourceIds;

            if (
                empty($resourceIds)
                && $this->hasResourceConstraint($effectivePartySize, $effectiveResourceFilters)
            ) {
                $autoResource = $this->pickAvailableResourceForReservation(
                    $accountId,
                    $newTeamMemberId,
                    $startUtc,
                    $endUtc,
                    $effectivePartySize,
                    $effectiveResourceFilters,
                    $reservation->id
                );

                if (!$autoResource) {
                    throw ValidationException::withMessages([
                        'starts_at' => ['Selected slot does not have enough resource capacity.'],
                    ]);
                }

                $resourceIds = [$autoResource->id];
            }

            $this->assertResourcesAvailable(
                $accountId,
                $newTeamMemberId,
                $startUtc,
                $endUtc,
                $resourceIds,
                $effectivePartySize,
                $reservation->id
            );

            $baseMetadata = array_key_exists('metadata', $payload)
                ? (is_array($payload['metadata']) ? $payload['metadata'] : [])
                : $existingMetadata;
            $metadata = $this->mergeReservationMetadata(
                $baseMetadata,
                $effectivePartySize,
                $effectiveResourceFilters,
                $resourceIds,
                $settings
            );

            $reservation->forceFill([
                'team_member_id' => $newTeamMemberId,
                'service_id' => $payload['service_id'] ?? $reservation->service_id,
                'status' => $payload['status'] ?? $reservation->status,
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => $bufferMinutes,
                'timezone' => $timezone,
                'internal_notes' => array_key_exists('internal_notes', $payload)
                    ? $payload['internal_notes']
                    : $reservation->internal_notes,
                'client_notes' => array_key_exists('client_notes', $payload)
                    ? $payload['client_notes']
                    : $reservation->client_notes,
                'metadata' => $metadata ?: null,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'cancelled_by_user_id' => null,
            ])->save();

            $this->syncResourceAllocations($reservation, $resourceIds);

            return $reservation->fresh();
        });
    }

    public function canClientModify(Reservation $reservation): bool
    {
        $settings = $this->resolveSettings($reservation->account_id, $reservation->team_member_id);
        $cutoffHours = max(0, (int) $settings['cancellation_cutoff_hours']);
        if ($cutoffHours <= 0) {
            return true;
        }

        $cutoffAt = $reservation->starts_at->copy()->subHours($cutoffHours);
        return now('UTC')->lt($cutoffAt);
    }

    public function metadataForStatusTransition(Reservation $reservation, string $nextStatus): ?array
    {
        $metadata = is_array($reservation->metadata) ? $reservation->metadata : [];
        $policy = $this->normalizePaymentPolicy($metadata['payment_policy'] ?? null);

        if (
            !$policy['deposit_required']
            && $policy['deposit_amount'] <= 0
            && !$policy['no_show_fee_enabled']
            && $policy['no_show_fee_amount'] <= 0
        ) {
            $settings = $this->resolveSettings((int) $reservation->account_id, (int) $reservation->team_member_id);
            $policy = $this->paymentPolicyFromSettings($settings);
        }

        $metadata['payment_policy'] = $policy;

        $state = is_array($metadata['payment_state'] ?? null) ? $metadata['payment_state'] : [];
        $state['deposit_status'] = (string) (
            $state['deposit_status']
            ?? ($policy['deposit_required'] ? 'required' : 'not_required')
        );
        $state['deposit_due_amount'] = $policy['deposit_required'] ? $policy['deposit_amount'] : 0.0;
        $state['no_show_fee_status'] = (string) (
            $state['no_show_fee_status']
            ?? ($policy['no_show_fee_enabled'] ? 'not_applied' : 'not_applicable')
        );
        $state['no_show_fee_amount'] = $policy['no_show_fee_enabled'] ? $policy['no_show_fee_amount'] : 0.0;

        if ($nextStatus === Reservation::STATUS_NO_SHOW) {
            if ($policy['no_show_fee_enabled']) {
                $state['no_show_fee_status'] = 'charge_required';
                $state['no_show_fee_recorded_at'] = now('UTC')->toIso8601String();
            }
            if ($policy['deposit_required']) {
                $state['deposit_status'] = 'forfeited';
            }
        } elseif ($nextStatus === Reservation::STATUS_COMPLETED) {
            if ($policy['deposit_required'] && $state['deposit_status'] === 'required') {
                $state['deposit_status'] = 'due_on_invoice';
            }
            if ($policy['no_show_fee_enabled']) {
                $state['no_show_fee_status'] = 'waived';
                unset($state['no_show_fee_recorded_at']);
            }
        } elseif ($nextStatus === Reservation::STATUS_CANCELLED) {
            if ($policy['deposit_required'] && $state['deposit_status'] === 'required') {
                $state['deposit_status'] = 'refundable';
            }
            if ($policy['no_show_fee_enabled'] && $state['no_show_fee_status'] === 'charge_required') {
                $state['no_show_fee_status'] = 'waived';
                unset($state['no_show_fee_recorded_at']);
            }
        } elseif ($policy['no_show_fee_enabled'] && $state['no_show_fee_status'] === 'charge_required') {
            $state['no_show_fee_status'] = 'not_applied';
            unset($state['no_show_fee_recorded_at']);
        }

        $metadata['payment_state'] = $state;

        return $metadata ?: null;
    }

    private function normalizeMoney(mixed $value): float
    {
        return max(0, round((float) $value, 2));
    }

    private function normalizePaymentPolicy(mixed $value): array
    {
        $policy = is_array($value) ? $value : [];
        $depositAmount = $this->normalizeMoney($policy['deposit_amount'] ?? 0);
        $noShowFeeAmount = $this->normalizeMoney($policy['no_show_fee_amount'] ?? 0);

        return [
            'deposit_required' => (bool) ($policy['deposit_required'] ?? false) && $depositAmount > 0,
            'deposit_amount' => $depositAmount,
            'no_show_fee_enabled' => (bool) ($policy['no_show_fee_enabled'] ?? false) && $noShowFeeAmount > 0,
            'no_show_fee_amount' => $noShowFeeAmount,
            'captured_at' => $policy['captured_at'] ?? now('UTC')->toIso8601String(),
        ];
    }

    private function paymentPolicyFromSettings(array $settings): array
    {
        return $this->normalizePaymentPolicy([
            'deposit_required' => (bool) ($settings['deposit_required'] ?? false),
            'deposit_amount' => $settings['deposit_amount'] ?? 0,
            'no_show_fee_enabled' => (bool) ($settings['no_show_fee_enabled'] ?? false),
            'no_show_fee_amount' => $settings['no_show_fee_amount'] ?? 0,
            'captured_at' => now('UTC')->toIso8601String(),
        ]);
    }

    private function mergePaymentPolicyMetadata(array $metadata, array $settings): array
    {
        $policy = $this->paymentPolicyFromSettings($settings);
        $metadata['payment_policy'] = $policy;

        $state = is_array($metadata['payment_state'] ?? null) ? $metadata['payment_state'] : [];
        $state['deposit_status'] = (string) (
            $state['deposit_status']
            ?? ($policy['deposit_required'] ? 'required' : 'not_required')
        );
        $state['deposit_due_amount'] = $policy['deposit_required'] ? $policy['deposit_amount'] : 0.0;
        $state['no_show_fee_status'] = (string) (
            $state['no_show_fee_status']
            ?? ($policy['no_show_fee_enabled'] ? 'not_applied' : 'not_applicable')
        );
        $state['no_show_fee_amount'] = $policy['no_show_fee_enabled'] ? $policy['no_show_fee_amount'] : 0.0;

        $metadata['payment_state'] = $state;

        return $metadata;
    }

    private function normalizePartySize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;
        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeResourceIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeResourceFilters(?array $filters): array
    {
        $filters = is_array($filters) ? $filters : [];

        $types = collect($filters['types'] ?? [])
            ->map(fn ($type) => strtolower(trim((string) $type)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $resourceIds = $this->normalizeResourceIds($filters['resource_ids'] ?? []);

        return [
            'types' => $types,
            'resource_ids' => $resourceIds,
        ];
    }

    private function hasResourceConstraint(?int $partySize, array $resourceFilters): bool
    {
        return ($partySize && $partySize > 0)
            || !empty($resourceFilters['types'])
            || !empty($resourceFilters['resource_ids']);
    }

    private function availableResourcesForMember(
        int $teamMemberId,
        Collection $resourcesByMember,
        array $resourceFilters,
        ?int $partySize
    ): Collection {
        $globalResources = $resourcesByMember->get('global', collect());
        $memberResources = $resourcesByMember->get((string) $teamMemberId, collect());
        $resources = $globalResources
            ->concat($memberResources)
            ->unique('id')
            ->values();

        $resourceIds = $resourceFilters['resource_ids'] ?? [];
        if (!empty($resourceIds)) {
            $allowed = array_flip($resourceIds);
            $resources = $resources
                ->filter(fn (ReservationResource $resource) => isset($allowed[(int) $resource->id]))
                ->values();
        }

        $types = $resourceFilters['types'] ?? [];
        if (!empty($types)) {
            $allowedTypes = array_flip($types);
            $resources = $resources
                ->filter(fn (ReservationResource $resource) => isset($allowedTypes[strtolower((string) $resource->type)]))
                ->values();
        }

        if ($partySize && $partySize > 0) {
            $resources = $resources
                ->filter(fn (ReservationResource $resource) => (int) $resource->capacity >= $partySize)
                ->values();
        }

        return $resources;
    }

    private function loadResourceAllocations(
        int $accountId,
        Carbon $startUtc,
        Carbon $endUtc,
        ?int $ignoreReservationId = null,
        bool $lockForUpdate = false
    ): Collection {
        $query = ReservationResourceAllocation::query()
            ->forAccount($accountId)
            ->with(['reservation:id,starts_at,ends_at,status'])
            ->whereHas('reservation', function ($reservationQuery) use ($startUtc, $endUtc, $ignoreReservationId) {
                $reservationQuery
                    ->whereIn('status', Reservation::ACTIVE_STATUSES)
                    ->where('starts_at', '<', $endUtc)
                    ->where('ends_at', '>', $startUtc)
                    ->when($ignoreReservationId, function ($query) use ($ignoreReservationId) {
                        $query->where('id', '!=', $ignoreReservationId);
                    });
            });

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->groupBy('reservation_resource_id');
    }

    private function calculateUsedCapacityForSlot(
        ReservationResource $resource,
        Carbon $slotStartUtc,
        Carbon $slotEndUtc,
        Collection $resourceAllocations
    ): int {
        $allocations = $resourceAllocations->get($resource->id, collect());
        $usedCapacity = 0;

        foreach ($allocations as $allocation) {
            $reservation = $allocation->reservation;
            if (!$reservation) {
                continue;
            }

            if (
                $reservation->starts_at
                && $reservation->ends_at
                && $reservation->starts_at->lt($slotEndUtc)
                && $reservation->ends_at->gt($slotStartUtc)
            ) {
                $usedCapacity += max(1, (int) ($allocation->quantity ?? 1));
            }
        }

        return $usedCapacity;
    }

    private function pickAvailableResourceForWindow(
        int $teamMemberId,
        Carbon $slotStartUtc,
        Carbon $slotEndUtc,
        Collection $resourcesByMember,
        Collection $resourceAllocations,
        ?int $partySize,
        array $resourceFilters
    ): ?ReservationResource {
        $resources = $this->availableResourcesForMember(
            $teamMemberId,
            $resourcesByMember,
            $resourceFilters,
            $partySize
        );

        if ($resources->isEmpty()) {
            return null;
        }

        $requiredCapacity = max(1, (int) ($partySize ?? 1));

        foreach ($resources as $resource) {
            $usedCapacity = $this->calculateUsedCapacityForSlot(
                $resource,
                $slotStartUtc,
                $slotEndUtc,
                $resourceAllocations
            );

            $capacity = max(1, (int) $resource->capacity);
            if (($usedCapacity + $requiredCapacity) <= $capacity) {
                return $resource;
            }
        }

        return null;
    }

    private function pickAvailableResourceForReservation(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        ?int $partySize,
        array $resourceFilters,
        ?int $ignoreReservationId
    ): ?ReservationResource {
        $resources = ReservationResource::query()
            ->forAccount($accountId)
            ->active()
            ->where(function ($query) use ($teamMemberId) {
                $query->whereNull('team_member_id')
                    ->orWhere('team_member_id', $teamMemberId);
            })
            ->lockForUpdate()
            ->get();

        if ($resources->isEmpty()) {
            return null;
        }

        $resourcesByMember = $resources->groupBy(function (ReservationResource $resource) {
            return $resource->team_member_id ? (string) $resource->team_member_id : 'global';
        });
        $allocations = $this->loadResourceAllocations(
            $accountId,
            $startUtc,
            $endUtc,
            $ignoreReservationId,
            true
        );

        return $this->pickAvailableResourceForWindow(
            $teamMemberId,
            $startUtc,
            $endUtc,
            $resourcesByMember,
            $allocations,
            $partySize,
            $resourceFilters
        );
    }

    private function assertResourcesAvailable(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        array $resourceIds,
        ?int $partySize,
        ?int $ignoreReservationId
    ): void {
        $resourceIds = $this->normalizeResourceIds($resourceIds);
        if (empty($resourceIds)) {
            return;
        }

        $resources = ReservationResource::query()
            ->forAccount($accountId)
            ->active()
            ->whereIn('id', $resourceIds)
            ->where(function ($query) use ($teamMemberId) {
                $query->whereNull('team_member_id')
                    ->orWhere('team_member_id', $teamMemberId);
            })
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($resources->count() !== count($resourceIds)) {
            throw ValidationException::withMessages([
                'resource_ids' => ['One or more selected resources are not available for this member.'],
            ]);
        }

        $allocations = $this->loadResourceAllocations(
            $accountId,
            $startUtc,
            $endUtc,
            $ignoreReservationId,
            true
        );

        $requiredCapacity = ($partySize && count($resourceIds) === 1)
            ? max(1, $partySize)
            : 1;

        foreach ($resourceIds as $resourceId) {
            $resource = $resources->get($resourceId);
            if (!$resource) {
                continue;
            }

            $capacity = max(1, (int) $resource->capacity);
            if ($requiredCapacity > $capacity) {
                throw ValidationException::withMessages([
                    'party_size' => ['Party size exceeds selected resource capacity.'],
                ]);
            }

            $usedCapacity = $this->calculateUsedCapacityForSlot(
                $resource,
                $startUtc,
                $endUtc,
                $allocations
            );

            if (($usedCapacity + $requiredCapacity) > $capacity) {
                throw ValidationException::withMessages([
                    'resource_ids' => ['Selected resources are no longer available for this slot.'],
                ]);
            }
        }
    }

    private function mergeReservationMetadata(
        array $metadata,
        ?int $partySize,
        array $resourceFilters,
        array $resourceIds,
        array $settings = []
    ): array {
        if ($partySize && $partySize > 0) {
            $metadata['party_size'] = $partySize;
        } else {
            unset($metadata['party_size']);
        }

        if (!empty($resourceFilters['types']) || !empty($resourceFilters['resource_ids'])) {
            $metadata['resource_filters'] = $resourceFilters;
        } else {
            unset($metadata['resource_filters']);
        }

        if (!empty($resourceIds)) {
            $metadata['resource_ids'] = array_values($resourceIds);
        } else {
            unset($metadata['resource_ids']);
        }

        if (!empty($settings)) {
            $metadata = $this->mergePaymentPolicyMetadata($metadata, $settings);
        }

        return $metadata;
    }

    private function syncResourceAllocations(Reservation $reservation, array $resourceIds): void
    {
        $resourceIds = $this->normalizeResourceIds($resourceIds);

        $baseQuery = ReservationResourceAllocation::query()
            ->forAccount((int) $reservation->account_id)
            ->where('reservation_id', $reservation->id);

        if (empty($resourceIds)) {
            $baseQuery->delete();
            return;
        }

        $existing = (clone $baseQuery)
            ->get()
            ->keyBy(function (ReservationResourceAllocation $allocation) {
                return (int) $allocation->reservation_resource_id;
            });

        (clone $baseQuery)
            ->whereNotIn('reservation_resource_id', $resourceIds)
            ->delete();

        foreach ($resourceIds as $resourceId) {
            $allocation = $existing->get($resourceId);
            if ($allocation) {
                if ((int) $allocation->quantity !== 1) {
                    $allocation->update(['quantity' => 1]);
                }
                continue;
            }

            ReservationResourceAllocation::query()->create([
                'account_id' => $reservation->account_id,
                'reservation_id' => $reservation->id,
                'reservation_resource_id' => $resourceId,
                'quantity' => 1,
            ]);
        }
    }

    private function parseToUtc(string $value, string $timezone): Carbon
    {
        return Carbon::parse($value, $timezone)->utc();
    }

    private function assertWithinAvailability(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        string $timezone
    ): void {
        $startLocal = $startUtc->copy()->setTimezone($timezone);
        $endLocal = $endUtc->copy()->setTimezone($timezone);
        if ($endLocal->toDateString() !== $startLocal->toDateString()) {
            throw ValidationException::withMessages([
                'starts_at' => ['Reservations cannot span multiple days.'],
            ]);
        }

        $weekly = WeeklyAvailability::query()
            ->forAccount($accountId)
            ->forTeamMember($teamMemberId)
            ->active()
            ->orderBy('start_time')
            ->get();

        $exceptions = AvailabilityException::query()
            ->forAccount($accountId)
            ->whereDate('date', $startLocal->toDateString())
            ->where(function ($query) use ($teamMemberId) {
                $query->whereNull('team_member_id')
                    ->orWhere('team_member_id', $teamMemberId);
            })
            ->get();

        $intervals = $this->buildDayIntervals(
            $teamMemberId,
            $startLocal->copy()->startOfDay(),
            $weekly,
            $exceptions,
            $timezone
        );

        $fits = collect($intervals)->contains(function (array $interval) use ($startLocal, $endLocal) {
            return $startLocal->gte($interval['start']) && $endLocal->lte($interval['end']);
        });

        if (!$fits) {
            throw ValidationException::withMessages([
                'starts_at' => ['Selected slot is outside configured availability.'],
            ]);
        }
    }

    private function assertNoDoubleBooking(
        int $accountId,
        int $teamMemberId,
        Carbon $startUtc,
        Carbon $endUtc,
        int $bufferMinutes,
        ?int $ignoreReservationId
    ): void {
        $windowStart = $startUtc->copy()->subMinutes(self::MAX_BUFFER_MINUTES);
        $windowEnd = $endUtc->copy()->addMinutes(self::MAX_BUFFER_MINUTES);

        $existing = Reservation::query()
            ->forAccount($accountId)
            ->where('team_member_id', $teamMemberId)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->when($ignoreReservationId, fn ($query) => $query->where('id', '!=', $ignoreReservationId))
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->lockForUpdate()
            ->get();

        foreach ($existing as $reservation) {
            $effectiveBuffer = max(
                $bufferMinutes,
                min(self::MAX_BUFFER_MINUTES, (int) ($reservation->buffer_minutes ?? 0))
            );
            $blockedStart = $reservation->starts_at->copy()->subMinutes($effectiveBuffer);
            $blockedEnd = $reservation->ends_at->copy()->addMinutes($effectiveBuffer);

            if ($startUtc->lt($blockedEnd) && $endUtc->gt($blockedStart)) {
                throw ValidationException::withMessages([
                    'starts_at' => ['Selected slot is no longer available.'],
                ]);
            }
        }
    }

    private function hasReservationConflict(
        Carbon $slotStart,
        Carbon $slotEnd,
        Collection $memberReservations,
        int $bufferMinutes,
        string $timezone
    ): bool {
        foreach ($memberReservations as $reservation) {
            $effectiveBuffer = max(
                $bufferMinutes,
                min(self::MAX_BUFFER_MINUTES, (int) ($reservation->buffer_minutes ?? 0))
            );
            $busyStart = $reservation->starts_at->copy()->setTimezone($timezone)->subMinutes($effectiveBuffer);
            $busyEnd = $reservation->ends_at->copy()->setTimezone($timezone)->addMinutes($effectiveBuffer);

            if ($slotStart->lt($busyEnd) && $slotEnd->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }

    private function passesNoticeRules(Carbon $slotStart, Carbon $nowLocal, array $settings): bool
    {
        $minNotice = max(0, (int) ($settings['min_notice_minutes'] ?? 0));
        if ($minNotice > 0 && $slotStart->lt($nowLocal->copy()->addMinutes($minNotice))) {
            return false;
        }

        $maxAdvanceDays = max(1, (int) ($settings['max_advance_days'] ?? 90));
        if ($slotStart->gt($nowLocal->copy()->addDays($maxAdvanceDays))) {
            return false;
        }

        return true;
    }

    private function buildDayIntervals(
        int $teamMemberId,
        Carbon $date,
        Collection $weeklyRows,
        Collection $exceptions,
        string $timezone
    ): array {
        $dayOfWeek = (int) $date->dayOfWeek;
        $weeklyIntervals = $weeklyRows
            ->filter(fn (WeeklyAvailability $row) => (int) $row->team_member_id === $teamMemberId)
            ->filter(fn (WeeklyAvailability $row) => (int) $row->day_of_week === $dayOfWeek)
            ->map(function (WeeklyAvailability $row) use ($date, $timezone) {
                $start = Carbon::parse($date->format('Y-m-d') . ' ' . $row->start_time, $timezone);
                $end = Carbon::parse($date->format('Y-m-d') . ' ' . $row->end_time, $timezone);
                return ['start' => $start, 'end' => $end];
            })
            ->filter(fn (array $interval) => $interval['end']->gt($interval['start']))
            ->values()
            ->all();

        $dayExceptions = $exceptions
            ->filter(fn (AvailabilityException $item) => $item->date?->toDateString() === $date->toDateString())
            ->filter(fn (AvailabilityException $item) => $item->team_member_id === null || (int) $item->team_member_id === $teamMemberId)
            ->values();

        $openIntervals = [];
        $closedIntervals = [];
        foreach ($dayExceptions as $exception) {
            if ($exception->start_time && $exception->end_time) {
                $start = Carbon::parse($date->format('Y-m-d') . ' ' . $exception->start_time, $timezone);
                $end = Carbon::parse($date->format('Y-m-d') . ' ' . $exception->end_time, $timezone);
            } else {
                $start = $date->copy()->startOfDay();
                $end = $date->copy()->endOfDay();
            }

            if ($end->lte($start)) {
                continue;
            }

            if ($exception->type === AvailabilityException::TYPE_OPEN) {
                $openIntervals[] = ['start' => $start, 'end' => $end];
                continue;
            }

            $closedIntervals[] = ['start' => $start, 'end' => $end];
        }

        $intervals = $this->normalizeIntervals(array_merge($weeklyIntervals, $openIntervals));
        foreach ($closedIntervals as $closed) {
            $intervals = $this->subtractIntervals($intervals, $closed);
        }

        return $this->normalizeIntervals($intervals);
    }

    private function normalizeIntervals(array $intervals): array
    {
        if (!$intervals) {
            return [];
        }

        usort($intervals, function (array $left, array $right) {
            if ($left['start']->eq($right['start'])) {
                return $left['end']->lt($right['end']) ? -1 : 1;
            }
            return $left['start']->lt($right['start']) ? -1 : 1;
        });

        $normalized = [];
        foreach ($intervals as $interval) {
            if (empty($normalized)) {
                $normalized[] = $interval;
                continue;
            }

            $lastIndex = count($normalized) - 1;
            $last = $normalized[$lastIndex];
            if ($interval['start']->lte($last['end'])) {
                if ($interval['end']->gt($last['end'])) {
                    $normalized[$lastIndex]['end'] = $interval['end'];
                }
                continue;
            }

            $normalized[] = $interval;
        }

        return $normalized;
    }

    private function subtractIntervals(array $intervals, array $closed): array
    {
        $results = [];
        foreach ($intervals as $interval) {
            if ($closed['end']->lte($interval['start']) || $closed['start']->gte($interval['end'])) {
                $results[] = $interval;
                continue;
            }

            if ($closed['start']->gt($interval['start'])) {
                $results[] = [
                    'start' => $interval['start'],
                    'end' => $closed['start']->copy(),
                ];
            }

            if ($closed['end']->lt($interval['end'])) {
                $results[] = [
                    'start' => $closed['end']->copy(),
                    'end' => $interval['end'],
                ];
            }
        }

        return $results;
    }

    private function alignToInterval(Carbon $dateTime, int $intervalMinutes): Carbon
    {
        $minutes = ((int) $dateTime->format('H')) * 60 + (int) $dateTime->format('i');
        $remainder = $minutes % $intervalMinutes;
        if ($remainder > 0) {
            $dateTime->addMinutes($intervalMinutes - $remainder);
        }

        return $dateTime->second(0);
    }

    /**
     * @return array<int, Carbon>
     */
    private function dateRange(Carbon $from, Carbon $to): array
    {
        $dates = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $dates[] = $cursor->copy();
        }
        return $dates;
    }
}
