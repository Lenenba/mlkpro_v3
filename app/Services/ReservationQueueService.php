<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationCheckIn;
use App\Models\ReservationQueueItem;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationQueueService
{
    public const DISPATCH_MODE_FIFO = 'fifo';
    public const DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY = 'fifo_with_appointment_priority';
    public const DISPATCH_MODE_SKILL_BASED = 'skill_based';

    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationNotificationService $notificationService
    ) {
    }

    public function syncAppointmentsForWindow(int $accountId, ?Carbon $start = null, ?Carbon $end = null): void
    {
        $start = ($start ?: now('UTC')->startOfDay())->copy()->utc();
        $end = ($end ?: now('UTC')->endOfDay())->copy()->utc();

        $reservations = Reservation::query()
            ->forAccount($accountId)
            ->whereBetween('starts_at', [$start, $end])
            ->whereIn('status', [
                Reservation::STATUS_PENDING,
                Reservation::STATUS_CONFIRMED,
                Reservation::STATUS_RESCHEDULED,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_NO_SHOW,
                Reservation::STATUS_CANCELLED,
            ])
            ->get([
                'id',
                'account_id',
                'team_member_id',
                'client_id',
                'client_user_id',
                'service_id',
                'status',
                'starts_at',
                'ends_at',
                'duration_minutes',
                'created_by_user_id',
                'source',
            ]);

        if ($reservations->isEmpty()) {
            return;
        }

        $existing = ReservationQueueItem::query()
            ->forAccount($accountId)
            ->whereIn('reservation_id', $reservations->pluck('id')->all())
            ->get()
            ->keyBy(fn (ReservationQueueItem $item) => (int) $item->reservation_id);

        foreach ($reservations as $reservation) {
            $item = $existing->get((int) $reservation->id);
            $status = match ((string) $reservation->status) {
                Reservation::STATUS_CANCELLED => ReservationQueueItem::STATUS_CANCELLED,
                Reservation::STATUS_COMPLETED => ReservationQueueItem::STATUS_DONE,
                Reservation::STATUS_NO_SHOW => ReservationQueueItem::STATUS_NO_SHOW,
                default => ($item && in_array($item->status, ReservationQueueItem::ACTIVE_STATUSES, true))
                    ? $item->status
                    : ReservationQueueItem::STATUS_NOT_ARRIVED,
            };

            $payload = [
                'account_id' => $accountId,
                'reservation_id' => $reservation->id,
                'client_id' => $reservation->client_id,
                'client_user_id' => $reservation->client_user_id,
                'service_id' => $reservation->service_id,
                'team_member_id' => $reservation->team_member_id,
                'created_by_user_id' => $reservation->created_by_user_id,
                'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                'source' => $reservation->source ?: 'reservation',
                'status' => $status,
                'estimated_duration_minutes' => max(5, (int) ($reservation->duration_minutes ?: 60)),
                'metadata' => array_replace_recursive((array) ($item?->metadata ?? []), [
                    'reservation' => [
                        'starts_at' => $reservation->starts_at?->toIso8601String(),
                        'ends_at' => $reservation->ends_at?->toIso8601String(),
                        'status' => $reservation->status,
                    ],
                ]),
            ];

            if ($item) {
                $item->fill($payload)->save();
            } else {
                ReservationQueueItem::query()->create($payload);
            }
        }
    }

    public function createTicket(int $accountId, array $payload, User $actor, ?array $settings = null): ReservationQueueItem
    {
        $settings = $settings ?: $this->availabilityService->resolveSettings($accountId, null);
        if (!($settings['queue_mode_enabled'] ?? false)) {
            throw ValidationException::withMessages(['queue' => ['Queue mode is disabled for this account.']]);
        }

        return DB::transaction(function () use ($accountId, $payload, $actor, $settings) {
            $serviceId = isset($payload['service_id']) ? (int) $payload['service_id'] : null;
            $teamMemberId = isset($payload['team_member_id']) ? (int) $payload['team_member_id'] : null;
            if ($teamMemberId) {
                $exists = TeamMember::query()->forAccount($accountId)->active()->whereKey($teamMemberId)->exists();
                if (!$exists) {
                    throw ValidationException::withMessages(['team_member_id' => ['Selected team member is not available.']]);
                }
            }

            $duration = isset($payload['estimated_duration_minutes'])
                ? max(5, min(240, (int) $payload['estimated_duration_minutes']))
                : $this->availabilityService->resolveDurationMinutes($accountId, $serviceId, null);

            $todayCount = ReservationQueueItem::query()
                ->forAccount($accountId)
                ->where('item_type', ReservationQueueItem::TYPE_TICKET)
                ->whereDate('created_at', now('UTC')->toDateString())
                ->lockForUpdate()
                ->count();

            $item = ReservationQueueItem::query()->create([
                'account_id' => $accountId,
                'client_id' => $payload['client_id'] ?? null,
                'client_user_id' => $payload['client_user_id'] ?? null,
                'service_id' => $serviceId,
                'team_member_id' => $teamMemberId ?: null,
                'created_by_user_id' => $actor->id,
                'item_type' => ReservationQueueItem::TYPE_TICKET,
                'source' => (string) ($payload['source'] ?? 'client'),
                'queue_number' => sprintf('T-%s-%03d', now('UTC')->format('md'), $todayCount + 1),
                'status' => ReservationQueueItem::STATUS_CHECKED_IN,
                'estimated_duration_minutes' => $duration,
                'checked_in_at' => now('UTC'),
                'metadata' => array_filter([
                    'notes' => !empty($payload['notes']) ? trim((string) $payload['notes']) : null,
                    'party_size' => !empty($payload['party_size']) ? max(1, (int) $payload['party_size']) : null,
                ]),
            ]);

            $this->recordCheckIn($item, $actor, (string) ($payload['source'] ?? 'client'));
            $this->refreshMetrics($accountId, $settings);

            return $item->fresh(['teamMember.user:id,name', 'service:id,name']);
        });
    }

    public function transition(
        ReservationQueueItem $item,
        string $action,
        User $actor,
        ?array $settings = null,
        array $context = []
    ): ReservationQueueItem {
        $settings = $settings ?: $this->availabilityService->resolveSettings((int) $item->account_id, null);
        $action = strtolower(trim($action));

        return DB::transaction(function () use ($item, $action, $actor, $settings, $context) {
            $locked = ReservationQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $terminal = [
                ReservationQueueItem::STATUS_DONE,
                ReservationQueueItem::STATUS_CANCELLED,
                ReservationQueueItem::STATUS_NO_SHOW,
                ReservationQueueItem::STATUS_LEFT,
            ];
            if (in_array($locked->status, $terminal, true)) {
                throw ValidationException::withMessages(['queue' => ['This queue item is already closed.']]);
            }

            $now = now('UTC');
            $payload = match ($action) {
                'check_in' => [
                    'status' => ReservationQueueItem::STATUS_CHECKED_IN,
                    'checked_in_at' => $now,
                    'pre_called_at' => null,
                    'called_at' => null,
                    'call_expires_at' => null,
                    'skipped_at' => null,
                ],
                'still_here' => [
                    'status' => ReservationQueueItem::STATUS_CHECKED_IN,
                    'checked_in_at' => $now,
                    'pre_called_at' => null,
                    'called_at' => null,
                    'call_expires_at' => null,
                ],
                'pre_call' => [
                    'status' => ReservationQueueItem::STATUS_PRE_CALLED,
                    'pre_called_at' => $now,
                ],
                'call' => [
                    'status' => ReservationQueueItem::STATUS_CALLED,
                    'called_at' => $now,
                    'call_expires_at' => $now->copy()->addMinutes((int) ($settings['queue_grace_minutes'] ?? 5)),
                ],
                'start' => [
                    'status' => ReservationQueueItem::STATUS_IN_SERVICE,
                    'started_at' => $now,
                ],
                'done' => [
                    'status' => ReservationQueueItem::STATUS_DONE,
                    'finished_at' => $now,
                    'call_expires_at' => null,
                ],
                'skip' => [
                    'status' => ReservationQueueItem::STATUS_SKIPPED,
                    'skipped_at' => $now,
                    'call_expires_at' => null,
                ],
                'cancel' => ($locked->item_type === ReservationQueueItem::TYPE_TICKET && ($context['by_client'] ?? false))
                    ? [
                        'status' => ReservationQueueItem::STATUS_LEFT,
                        'left_at' => $now,
                        'call_expires_at' => null,
                    ]
                    : [
                        'status' => ReservationQueueItem::STATUS_CANCELLED,
                        'cancelled_at' => $now,
                        'call_expires_at' => null,
                    ],
                default => throw ValidationException::withMessages(['queue' => ['Unsupported queue action.']]),
            };

            if (array_key_exists('team_member_id', $context)) {
                $payload['team_member_id'] = $context['team_member_id'] ? (int) $context['team_member_id'] : null;
            }

            $locked->fill($payload)->save();
            if (in_array($action, ['check_in', 'still_here'], true)) {
                $this->recordCheckIn($locked, $actor, $action === 'still_here' ? 'still_here' : ((string) ($context['channel'] ?? 'staff')));
            }

            $this->refreshMetrics((int) $locked->account_id, $settings);

            return $locked->fresh(['teamMember.user:id,name', 'service:id,name', 'reservation:id,starts_at,status']);
        });
    }

    public function boardForStaff(int $accountId, array $access, array $settings): array
    {
        if (!($settings['queue_mode_enabled'] ?? false)) {
            return ['items' => [], 'stats' => ['waiting' => 0, 'called' => 0, 'in_service' => 0]];
        }

        $this->syncAppointmentsForWindow($accountId, now('UTC')->startOfDay(), now('UTC')->addDay()->endOfDay());
        $metrics = $this->refreshMetrics($accountId, $settings);

        $query = ReservationQueueItem::query()
            ->forAccount($accountId)
            ->with(['teamMember.user:id,name', 'service:id,name', 'client:id,first_name,last_name,company_name,email', 'reservation:id,starts_at,status'])
            ->whereIn('status', array_merge(ReservationQueueItem::ACTIVE_STATUSES, [ReservationQueueItem::STATUS_DONE]))
            ->where(function ($builder) {
                $builder->whereIn('status', ReservationQueueItem::ACTIVE_STATUSES)
                    ->orWhere('finished_at', '>=', now('UTC')->subHours(2));
            });

        if (!($access['can_view_all'] ?? false) && !empty($access['own_team_member_id'])) {
            $ownTeamMemberId = (int) $access['own_team_member_id'];
            $query->where(function ($builder) use ($ownTeamMemberId) {
                $builder->where('team_member_id', $ownTeamMemberId)->orWhereNull('team_member_id');
            });
        }

        $items = $query
            ->orderByRaw('COALESCE(team_member_id, 2147483647)')
            ->orderBy('position')
            ->orderBy('created_at')
            ->limit(80)
            ->get()
            ->map(function (ReservationQueueItem $item) use ($metrics, $access) {
                $clientName = $item->client?->company_name ?: trim(($item->client?->first_name ?? '') . ' ' . ($item->client?->last_name ?? ''));
                $ownTeamMemberId = (int) ($access['own_team_member_id'] ?? 0);
                $canManage = (bool) ($access['can_manage'] ?? false);
                return [
                    'id' => $item->id,
                    'reservation_id' => $item->reservation_id,
                    'item_type' => $item->item_type,
                    'queue_number' => $item->queue_number,
                    'status' => $item->status,
                    'client_name' => $clientName ?: ($item->client?->email ?? null),
                    'service_name' => $item->service?->name,
                    'team_member_id' => $item->team_member_id,
                    'team_member_name' => $item->teamMember?->user?->name,
                    'reservation_starts_at' => $item->reservation?->starts_at?->toIso8601String(),
                    'estimated_duration_minutes' => (int) ($item->estimated_duration_minutes ?? 0),
                    'position' => $item->position,
                    'eta_minutes' => $item->eta_minutes,
                    'callable' => (bool) ($metrics[$item->id]['callable'] ?? false),
                    'recommended_team_member_id' => $metrics[$item->id]['recommended_team_member_id'] ?? null,
                    'call_expires_at' => $item->call_expires_at?->toIso8601String(),
                    'can_update_status' => $canManage || ($ownTeamMemberId > 0 && ((int) ($item->team_member_id ?? 0) === $ownTeamMemberId || $item->team_member_id === null)),
                ];
            })->values()->all();

        return [
            'items' => $items,
            'stats' => [
                'waiting' => count(array_filter($items, fn (array $item) => in_array($item['status'], [
                    ReservationQueueItem::STATUS_CHECKED_IN,
                    ReservationQueueItem::STATUS_PRE_CALLED,
                    ReservationQueueItem::STATUS_CALLED,
                    ReservationQueueItem::STATUS_SKIPPED,
                ], true))),
                'called' => count(array_filter($items, fn (array $item) => $item['status'] === ReservationQueueItem::STATUS_CALLED)),
                'in_service' => count(array_filter($items, fn (array $item) => $item['status'] === ReservationQueueItem::STATUS_IN_SERVICE)),
            ],
        ];
    }

    public function clientTickets(int $accountId, int $customerId, int $clientUserId, array $settings, int $limit = 20): array
    {
        if (!($settings['queue_mode_enabled'] ?? false)) {
            return [];
        }

        $this->refreshMetrics($accountId, $settings);

        return ReservationQueueItem::query()
            ->forAccount($accountId)
            ->where('item_type', ReservationQueueItem::TYPE_TICKET)
            ->where(function ($query) use ($customerId, $clientUserId) {
                $query->where('client_user_id', $clientUserId)->orWhere('client_id', $customerId);
            })
            ->where(function ($query) {
                $query->whereIn('status', ReservationQueueItem::ACTIVE_STATUSES)
                    ->orWhere('updated_at', '>=', now('UTC')->subDays(2));
            })
            ->with(['teamMember.user:id,name', 'service:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ReservationQueueItem $item) => [
                'id' => $item->id,
                'queue_number' => $item->queue_number,
                'status' => $item->status,
                'service_name' => $item->service?->name,
                'team_member_name' => $item->teamMember?->user?->name,
                'position' => $item->position,
                'eta_minutes' => $item->eta_minutes,
                'call_expires_at' => $item->call_expires_at?->toIso8601String(),
                'created_at' => $item->created_at?->toIso8601String(),
                'can_cancel' => in_array($item->status, ReservationQueueItem::ACTIVE_STATUSES, true),
                'can_still_here' => in_array($item->status, [
                    ReservationQueueItem::STATUS_CHECKED_IN,
                    ReservationQueueItem::STATUS_PRE_CALLED,
                    ReservationQueueItem::STATUS_CALLED,
                    ReservationQueueItem::STATUS_SKIPPED,
                ], true),
            ])
            ->values()
            ->all();
    }

    public function refreshMetrics(int $accountId, ?array $settings = null): array
    {
        $settings = $settings ?: $this->availabilityService->resolveSettings($accountId, null);
        $this->expireGraceItems($accountId, $settings);

        $items = ReservationQueueItem::query()->forAccount($accountId)->active()->with(['reservation:id,starts_at', 'teamMember:id'])->orderBy('created_at')->get();
        if ($items->isEmpty()) {
            return [];
        }

        $dispatchMode = $this->normalizeDispatchMode((string) ($settings['queue_dispatch_mode'] ?? self::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY));
        $buffer = max(0, (int) ($settings['buffer_minutes'] ?? 0));
        $now = now('UTC');
        $teamIds = TeamMember::query()->forAccount($accountId)->active()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $nextAppointmentsByMember = Reservation::query()
            ->forAccount($accountId)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('starts_at', '>', $now)
            ->whereNotNull('team_member_id')
            ->orderBy('starts_at')
            ->get(['team_member_id', 'starts_at'])
            ->groupBy(fn (Reservation $reservation) => (int) $reservation->team_member_id)
            ->map(fn (Collection $group) => $group->first()?->starts_at);

        $ordered = $items->all();
        usort($ordered, function (ReservationQueueItem $left, ReservationQueueItem $right) use ($dispatchMode) {
            $weight = fn (string $status) => match ($status) {
                ReservationQueueItem::STATUS_IN_SERVICE => 1,
                ReservationQueueItem::STATUS_CALLED => 2,
                ReservationQueueItem::STATUS_PRE_CALLED => 3,
                ReservationQueueItem::STATUS_CHECKED_IN => 4,
                ReservationQueueItem::STATUS_SKIPPED => 5,
                ReservationQueueItem::STATUS_NOT_ARRIVED => 6,
                default => 99,
            };
            $cmp = $weight($left->status) <=> $weight($right->status);
            if ($cmp !== 0) {
                return $cmp;
            }
            if (
                $dispatchMode === self::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY
                && in_array($left->status, [ReservationQueueItem::STATUS_CHECKED_IN, ReservationQueueItem::STATUS_PRE_CALLED, ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_SKIPPED], true)
                && in_array($right->status, [ReservationQueueItem::STATUS_CHECKED_IN, ReservationQueueItem::STATUS_PRE_CALLED, ReservationQueueItem::STATUS_CALLED, ReservationQueueItem::STATUS_SKIPPED], true)
                && $left->item_type !== $right->item_type
            ) {
                return $left->item_type === ReservationQueueItem::TYPE_APPOINTMENT ? -1 : 1;
            }
            $leftAnchor = $left->item_type === ReservationQueueItem::TYPE_APPOINTMENT ? ($left->reservation?->starts_at ?: $left->created_at) : ($left->checked_in_at ?: $left->created_at);
            $rightAnchor = $right->item_type === ReservationQueueItem::TYPE_APPOINTMENT ? ($right->reservation?->starts_at ?: $right->created_at) : ($right->checked_in_at ?: $right->created_at);
            return $leftAnchor->eq($rightAnchor) ? ($left->id <=> $right->id) : ($leftAnchor->lt($rightAnchor) ? -1 : 1);
        });

        $metrics = [];
        $positionByLane = [];
        $etaByLane = [];
        foreach ($ordered as $item) {
            $callable = false;
            $recommendedMember = null;
            if ($item->item_type === ReservationQueueItem::TYPE_APPOINTMENT) {
                $callable = in_array($item->status, [
                    ReservationQueueItem::STATUS_CHECKED_IN,
                    ReservationQueueItem::STATUS_PRE_CALLED,
                    ReservationQueueItem::STATUS_CALLED,
                    ReservationQueueItem::STATUS_SKIPPED,
                ], true);
            } elseif (in_array($item->status, ReservationQueueItem::CALLABLE_STATUSES, true)) {
                $duration = max(5, (int) ($item->estimated_duration_minutes ?: 60));
                $fits = function (int $memberId) use ($nextAppointmentsByMember, $duration, $buffer, $now): bool {
                    $next = $nextAppointmentsByMember->get($memberId);
                    if (!$next instanceof Carbon) {
                        return true;
                    }
                    return $now->diffInMinutes($next, false) >= ($duration + $buffer);
                };
                if ($item->team_member_id) {
                    $callable = $fits((int) $item->team_member_id);
                } else {
                    foreach ($teamIds as $teamId) {
                        if ($fits($teamId)) {
                            $callable = true;
                            $recommendedMember = $teamId;
                            break;
                        }
                    }
                }
            }

            $laneMemberId = $item->team_member_id
                ? (int) $item->team_member_id
                : ($recommendedMember ? (int) $recommendedMember : 0);
            if (!array_key_exists($laneMemberId, $positionByLane)) {
                $positionByLane[$laneMemberId] = 0;
            }
            if (!array_key_exists($laneMemberId, $etaByLane)) {
                $etaByLane[$laneMemberId] = 0;
            }

            if ($item->status === ReservationQueueItem::STATUS_IN_SERVICE) {
                $etaByLane[$laneMemberId] += max(5, (int) ($item->estimated_duration_minutes ?: 60));
            }

            $positionValue = null;
            $etaValue = null;
            if (in_array($item->status, [
                ReservationQueueItem::STATUS_CHECKED_IN,
                ReservationQueueItem::STATUS_PRE_CALLED,
                ReservationQueueItem::STATUS_CALLED,
                ReservationQueueItem::STATUS_SKIPPED,
            ], true)) {
                $positionByLane[$laneMemberId]++;
                $positionValue = $positionByLane[$laneMemberId];
                $etaValue = $etaByLane[$laneMemberId];
                if ($callable) {
                    $etaByLane[$laneMemberId] += max(5, (int) ($item->estimated_duration_minutes ?: 60));
                }
            }

            $metrics[$item->id] = [
                'position' => $positionValue,
                'eta_minutes' => $etaValue,
                'callable' => $callable,
                'recommended_team_member_id' => $recommendedMember,
            ];
        }

        foreach ($ordered as $item) {
            $nextPosition = $metrics[$item->id]['position'] ?? null;
            $nextEta = $metrics[$item->id]['eta_minutes'] ?? null;
            if ((int) ($item->position ?? -1) !== (int) ($nextPosition ?? -1) || (int) ($item->eta_minutes ?? -1) !== (int) ($nextEta ?? -1)) {
                $item->update(['position' => $nextPosition, 'eta_minutes' => $nextEta]);
            }
        }

        return $metrics;
    }

    private function recordCheckIn(ReservationQueueItem $item, ?User $actor, string $channel): void
    {
        ReservationCheckIn::query()->create([
            'account_id' => $item->account_id,
            'reservation_queue_item_id' => $item->id,
            'reservation_id' => $item->reservation_id,
            'client_user_id' => $item->client_user_id,
            'checked_in_by_user_id' => $actor?->id,
            'channel' => $channel,
            'checked_in_at' => now('UTC'),
            'grace_deadline_at' => $item->call_expires_at,
        ]);
    }

    private function normalizeDispatchMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, [self::DISPATCH_MODE_FIFO, self::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY, self::DISPATCH_MODE_SKILL_BASED], true)
            ? $mode
            : self::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY;
    }

    private function expireGraceItems(int $accountId, array $settings): void
    {
        $expired = ReservationQueueItem::query()
            ->forAccount($accountId)
            ->where('status', ReservationQueueItem::STATUS_CALLED)
            ->whereNotNull('call_expires_at')
            ->where('call_expires_at', '<', now('UTC'))
            ->get();

        foreach ($expired as $item) {
            if (($settings['queue_no_show_on_grace_expiry'] ?? false) && $item->item_type === ReservationQueueItem::TYPE_APPOINTMENT) {
                $item->update([
                    'status' => ReservationQueueItem::STATUS_NO_SHOW,
                    'finished_at' => now('UTC'),
                    'call_expires_at' => null,
                ]);
            } else {
                $item->update([
                    'status' => ReservationQueueItem::STATUS_SKIPPED,
                    'skipped_at' => now('UTC'),
                    'call_expires_at' => null,
                ]);
            }

            $this->notificationService->handleQueueEvent($item->fresh([
                'service:id,name',
                'teamMember.user:id,name,email',
                'client:id,first_name,last_name,company_name,email,portal_user_id',
                'client.portalUser:id,name,email',
                'clientUser:id,name,email',
                'reservation:id,starts_at,status,team_member_id,client_id,client_user_id',
                'reservation.client:id,first_name,last_name,company_name,email,portal_user_id',
                'reservation.client.portalUser:id,name,email',
                'reservation.clientUser:id,name,email',
                'reservation.teamMember.user:id,name,email',
            ]), 'queue_grace_expired');
        }
    }
}
