<?php

namespace App\Queries\Customers;

use App\Http\Requests\CustomerActivityRequest;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\Rbac\AccessControl;
use App\Support\CRM\MeetingEventTaxonomy;
use App\Support\CRM\MessageEventTaxonomy;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BuildCustomerTimelineData
{
    private const SOURCE_ACTIVITY = 'activity';

    private const SOURCE_CAMPAIGN = 'campaign';

    private const SOURCE_INVOICE = 'invoice';

    private const SOURCE_PAYMENT = 'payment';

    private const SOURCE_RESERVATION = 'reservation';

    private const PROFILE_ACTIONS = [
        'created',
        'updated',
        'deleted',
        'profile_updated',
        'status_changed',
        'tags_updated',
        'auto_validation_updated',
        'customer_vip_updated',
        'customer_vip_auto_synced',
        'customer_archived',
        'customer_restored',
        'portal_access_enabled',
        'portal_access_disabled',
    ];

    private const NOTE_ACTIONS = [
        'note_added',
        'notes_updated',
        'sales_note_added',
    ];

    public function __construct(
        private readonly CompanyFeatureService $featureService,
        private readonly AccessControl $accessControl,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>, links: array<string, string|null>}
     */
    public function execute(
        Customer $customer,
        User $actor,
        User $accountOwner,
        int $accountId,
        array $filters,
        string $endpoint
    ): array {
        $timezone = $this->timezone($accountOwner);
        $currencyCode = $accountOwner->businessCurrencyCode();
        $capabilities = $this->capabilities($actor, $accountOwner, $accountId);
        $availableTypes = collect(CustomerActivityRequest::TYPES)
            ->filter(fn (string $type): bool => (bool) ($capabilities[$type] ?? false))
            ->values()
            ->all();
        $requestedTypes = isset($filters['types']) && is_array($filters['types']) && $filters['types'] !== []
            ? array_values(array_intersect(CustomerActivityRequest::TYPES, $filters['types'], $availableTypes))
            : $availableTypes;
        $period = in_array($filters['period'] ?? null, CustomerActivityRequest::PERIODS, true)
            ? (string) $filters['period']
            : 'last_90_days';
        [$fromUtc, $toUtc, $fromLocal, $toLocal] = $this->periodBounds(
            $period,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $timezone
        );
        $cursor = $this->decodeCursor($filters['cursor'] ?? null);
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $events = collect();

        if (in_array('appointments', $requestedTypes, true)) {
            $events->push(...$this->appointments(
                $customer,
                $actor,
                $accountId,
                $fromUtc,
                $toUtc,
                $cursor,
                $perPage
            ));
        }

        if (in_array('invoices', $requestedTypes, true)) {
            $events->push(...$this->invoices(
                $customer,
                $accountId,
                $currencyCode,
                $fromUtc,
                $toUtc,
                $cursor,
                $perPage
            ));
        }

        if (in_array('payments', $requestedTypes, true)) {
            $events->push(...$this->payments(
                $customer,
                $accountId,
                $currencyCode,
                $fromUtc,
                $toUtc,
                $cursor,
                $perPage
            ));
        }

        $activityTypes = array_values(array_intersect($requestedTypes, [
            ...($capabilities['notes'] ? ['notes'] : []),
            ...($capabilities['activity_communications'] ? ['communications'] : []),
            'profile_changes',
        ]));
        if ($activityTypes !== []) {
            $events->push(...$this->activityLogs(
                $customer,
                $accountId,
                $activityTypes,
                $capabilities,
                $fromUtc,
                $toUtc,
                $cursor,
                $perPage
            ));
        }

        if (in_array('communications', $requestedTypes, true) && $capabilities['campaign_events']) {
            $events->push(...$this->campaignEvents(
                $customer,
                $accountId,
                $fromUtc,
                $toUtc,
                $cursor,
                $perPage
            ));
        }

        $events = $events
            ->filter(fn (array $event): bool => $this->isAfterCursor($event, $cursor))
            ->sort(fn (array $left, array $right): int => $this->compareEvents($left, $right))
            ->values();
        $hasMore = $events->count() > $perPage;
        $page = $events->take($perPage)->values();
        $last = $page->last();
        $nextCursor = $hasMore && is_array($last) ? $this->encodeCursor($last) : null;
        $nextFilters = array_filter([
            'period' => $period,
            'from' => $period === 'custom' ? $fromLocal : null,
            'to' => $period === 'custom' ? $toLocal : null,
            'types' => $requestedTypes !== $availableTypes ? $requestedTypes : null,
            'cursor' => $nextCursor,
            'per_page' => $perPage,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return [
            'data' => $page
                ->map(fn (array $event): array => collect($event)->except([
                    '_sort_at',
                    '_source',
                    '_source_id',
                ])->all())
                ->all(),
            'meta' => [
                'period' => $period,
                'from' => $fromLocal,
                'to' => $toLocal,
                'types' => $requestedTypes === $availableTypes ? [] : $requestedTypes,
                'available_types' => $availableTypes,
                'timezone' => $timezone,
                'per_page' => $perPage,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'links' => [
                'next' => $nextCursor ? $endpoint.'?'.http_build_query($nextFilters) : null,
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(User $actor, User $accountOwner, int $accountId): array
    {
        $hasReservations = $this->featureService->hasFeature($accountOwner, 'reservations')
            && $this->accessControl->userHasPermission($actor, 'reservations.view', $accountId);
        $hasInvoices = $this->featureService->hasFeature($accountOwner, 'invoices')
            && $actor->can('viewAny', Invoice::class);
        $hasCampaignEvents = $this->featureService->hasFeature($accountOwner, 'campaigns')
            && $actor->can('viewAny', Campaign::class);
        $canViewNotes = $this->accessControl->userHasPermission($actor, 'view_client_notes', $accountId)
            || $this->accessControl->userHasPermission($actor, 'manage_client_notes', $accountId);
        $canViewSalesActivity = $this->featureService->hasFeature($accountOwner, 'sales')
            && $this->accessControl->userHasPermission($actor, 'view_sales', $accountId);

        return [
            'appointments' => $hasReservations,
            'invoices' => $hasInvoices,
            'payments' => $hasInvoices,
            'notes' => $canViewNotes,
            'communications' => $canViewSalesActivity || $hasCampaignEvents,
            'profile_changes' => (int) $actor->accountOwnerId() === $accountId,
            'campaign_events' => $hasCampaignEvents,
            'activity_communications' => $canViewSalesActivity,
            'requests' => $this->featureService->hasFeature($accountOwner, 'requests')
                && $this->accessControl->userHasPermission($actor, 'requests.view', $accountId),
            'quotes' => $this->featureService->hasFeature($accountOwner, 'quotes')
                && $this->accessControl->userHasPermission($actor, 'quotes.view', $accountId),
        ];
    }

    /**
     * @param  array{at: string, source: string, id: int}|null  $cursor
     * @return array<int, array<string, mixed>>
     */
    private function appointments(
        Customer $customer,
        User $actor,
        int $accountId,
        ?Carbon $fromUtc,
        ?Carbon $toUtc,
        ?array $cursor,
        int $limit
    ): array {
        $dateSql = "CASE WHEN status = 'cancelled' AND cancelled_at IS NOT NULL THEN cancelled_at ELSE starts_at END";
        $query = Reservation::query()
            ->forAccount($accountId)
            ->where('client_id', $customer->id)
            ->with([
                'teamMember.user:id,name',
                'service:id,name',
                'creator:id,name',
                'canceller:id,name',
            ]);
        $this->scopeReservationsForActor($query, $actor, $accountId);
        $this->applyDateBounds($query, $dateSql, $fromUtc, $toUtc);
        $this->applyCursor($query, $dateSql, self::SOURCE_RESERVATION, $cursor);

        return $query
            ->select('reservations.*')
            ->selectRaw($dateSql.' as timeline_occurred_at')
            ->orderByRaw($dateSql.' DESC')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(function (Reservation $reservation): array {
                $status = $this->appointmentStatus($reservation);
                $actor = $reservation->status === Reservation::STATUS_CANCELLED
                    ? $reservation->canceller
                    : ($reservation->teamMember?->user ?? $reservation->creator);
                $service = trim((string) ($reservation->service?->name ?? ''));
                $member = trim((string) ($reservation->teamMember?->user?->name ?? ''));
                $description = collect([$service, $member])->filter()->implode(' · ');

                return $this->event(
                    self::SOURCE_RESERVATION,
                    (int) $reservation->id,
                    $reservation->getAttribute('timeline_occurred_at'),
                    'appointments',
                    $status,
                    $this->appointmentTitle($status),
                    $description !== '' ? $description : null,
                    null,
                    [
                        'type' => 'reservation',
                        'id' => (int) $reservation->id,
                        'href' => route('reservation.index', ['reservation_id' => $reservation->id], false),
                    ],
                    $this->appointmentIcon($status),
                    $actor ? ['id' => (int) $actor->id, 'name' => (string) $actor->name] : null,
                    [
                        'starts_at' => $reservation->starts_at?->utc()->toIso8601String(),
                        'ends_at' => $reservation->ends_at?->utc()->toIso8601String(),
                        'cancel_reason' => $reservation->cancel_reason,
                    ]
                );
            })
            ->all();
    }

    private function scopeReservationsForActor(Builder $query, User $actor, int $accountId): void
    {
        if ((int) $actor->id === $accountId) {
            return;
        }

        $membership = TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $actor->id)
            ->first();
        $canViewAll = $membership
            && $this->accessControl->userHasPermission($actor, 'view_all_reservations', $accountId);

        if (! $canViewAll) {
            $query->where('team_member_id', (int) ($membership?->id ?? 0));
        }
    }

    /**
     * @param  array{at: string, source: string, id: int}|null  $cursor
     * @return array<int, array<string, mixed>>
     */
    private function invoices(
        Customer $customer,
        int $accountId,
        string $currencyCode,
        ?Carbon $fromUtc,
        ?Carbon $toUtc,
        ?array $cursor,
        int $limit
    ): array {
        $query = Invoice::query()
            ->where('user_id', $accountId)
            ->where('customer_id', $customer->id)
            ->whereNull('deleted_at')
            ->where('currency_code', $currencyCode)
            ->with('creator:id,name');
        $this->applyDateBounds($query, 'created_at', $fromUtc, $toUtc);
        $this->applyCursor($query, 'created_at', self::SOURCE_INVOICE, $cursor);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get(['id', 'number', 'status', 'total', 'currency_code', 'created_by_user_id', 'created_at'])
            ->map(fn (Invoice $invoice): array => $this->event(
                self::SOURCE_INVOICE,
                (int) $invoice->id,
                $invoice->created_at,
                'invoices',
                (string) $invoice->status,
                $invoice->number ? 'Invoice '.$invoice->number : 'Invoice created',
                null,
                [
                    'value' => round((float) $invoice->total, 2),
                    'currency_code' => (string) ($invoice->currency_code ?: $currencyCode),
                ],
                [
                    'type' => 'invoice',
                    'id' => (int) $invoice->id,
                    'href' => route('invoice.show', $invoice, false),
                ],
                'document-text',
                $invoice->creator ? [
                    'id' => (int) $invoice->creator->id,
                    'name' => (string) $invoice->creator->name,
                ] : null,
                ['number' => $invoice->number]
            ))
            ->all();
    }

    /**
     * @param  array{at: string, source: string, id: int}|null  $cursor
     * @return array<int, array<string, mixed>>
     */
    private function payments(
        Customer $customer,
        int $accountId,
        string $currencyCode,
        ?Carbon $fromUtc,
        ?Carbon $toUtc,
        ?array $cursor,
        int $limit
    ): array {
        $dateSql = 'COALESCE(paid_at, created_at)';
        $query = Payment::query()
            ->where('user_id', $accountId)
            ->where('customer_id', $customer->id)
            ->where('currency_code', $currencyCode)
            ->whereIn('status', [
                Payment::STATUS_PENDING,
                Payment::STATUS_PAID,
                Payment::STATUS_COMPLETED,
                Payment::STATUS_FAILED,
                Payment::STATUS_REFUNDED,
                Payment::STATUS_REVERSED,
            ])
            ->where(function (Builder $sourceQuery) use ($accountId, $customer, $currencyCode): void {
                $sourceQuery->whereNull('invoice_id')
                    ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery
                        ->where('user_id', $accountId)
                        ->where('customer_id', $customer->id)
                        ->whereNull('deleted_at')
                        ->where('currency_code', $currencyCode));
            })
            ->with([
                'invoice' => fn ($invoiceQuery) => $invoiceQuery
                    ->where('user_id', $accountId)
                    ->where('customer_id', $customer->id)
                    ->whereNull('deleted_at')
                    ->where('currency_code', $currencyCode)
                    ->select(['id', 'number', 'user_id', 'customer_id', 'currency_code']),
                'user:id,name',
            ]);
        $this->applyDateBounds($query, $dateSql, $fromUtc, $toUtc);
        $this->applyCursor($query, $dateSql, self::SOURCE_PAYMENT, $cursor);

        return $query
            ->select('payments.*')
            ->selectRaw($dateSql.' as timeline_occurred_at')
            ->orderByRaw($dateSql.' DESC')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(function (Payment $payment) use ($currencyCode): array {
                $negative = in_array($payment->status, [Payment::STATUS_REFUNDED, Payment::STATUS_REVERSED], true);
                $amount = round((float) $payment->amount, 2);

                return $this->event(
                    self::SOURCE_PAYMENT,
                    (int) $payment->id,
                    $payment->getAttribute('timeline_occurred_at'),
                    'payments',
                    (string) $payment->status,
                    $this->paymentTitle((string) $payment->status),
                    $payment->invoice?->number ? 'Invoice '.$payment->invoice->number : null,
                    [
                        'value' => $negative ? -abs($amount) : $amount,
                        'currency_code' => (string) ($payment->currency_code ?: $currencyCode),
                    ],
                    $payment->invoice ? [
                        'type' => 'invoice',
                        'id' => (int) $payment->invoice->id,
                        'href' => route('invoice.show', $payment->invoice, false),
                    ] : null,
                    $negative ? 'arrow-uturn-left' : 'credit-card',
                    $payment->user ? [
                        'id' => (int) $payment->user->id,
                        'name' => (string) $payment->user->name,
                    ] : null,
                    [
                        'method' => $payment->method,
                        'reference' => $payment->reference,
                        'invoice_id' => $payment->invoice_id,
                    ]
                );
            })
            ->all();
    }

    /**
     * @param  array{at: string, source: string, id: int}|null  $cursor
     * @return array<int, array<string, mixed>>
     */
    private function campaignEvents(
        Customer $customer,
        int $accountId,
        ?Carbon $fromUtc,
        ?Carbon $toUtc,
        ?array $cursor,
        int $limit
    ): array {
        $dateSql = 'COALESCE(occurred_at, created_at)';
        $query = CampaignEvent::query()
            ->where('user_id', $accountId)
            ->where('customer_id', $customer->id)
            ->whereHas('campaign', fn (Builder $campaignQuery) => $campaignQuery
                ->where('user_id', $accountId))
            ->with([
                'campaign' => fn ($campaignQuery) => $campaignQuery
                    ->where('user_id', $accountId)
                    ->select(['id', 'user_id', 'name']),
                'user:id,name',
            ]);
        $this->applyDateBounds($query, $dateSql, $fromUtc, $toUtc);
        $this->applyCursor($query, $dateSql, self::SOURCE_CAMPAIGN, $cursor);

        return $query
            ->select('campaign_events.*')
            ->selectRaw($dateSql.' as timeline_occurred_at')
            ->orderByRaw($dateSql.' DESC')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(function (CampaignEvent $event): array {
                $eventType = strtolower((string) $event->event_type);
                $campaignName = trim((string) ($event->campaign?->name ?? ''));
                $channel = trim((string) ($event->channel ?? ''));

                return $this->event(
                    self::SOURCE_CAMPAIGN,
                    (int) $event->id,
                    $event->getAttribute('timeline_occurred_at'),
                    'communications',
                    $eventType !== '' ? $eventType : 'recorded',
                    $this->campaignEventTitle($eventType),
                    collect([$campaignName, $channel !== '' ? Str::headline($channel) : null])
                        ->filter()
                        ->implode(' · ') ?: null,
                    null,
                    $event->campaign ? [
                        'type' => 'campaign',
                        'id' => (int) $event->campaign->id,
                        'href' => route('campaigns.show', $event->campaign, false),
                    ] : null,
                    'megaphone',
                    $event->user ? [
                        'id' => (int) $event->user->id,
                        'name' => (string) $event->user->name,
                    ] : null,
                    [
                        'channel' => $event->channel,
                        'event_type' => $event->event_type,
                        'conversion_type' => $event->conversion_type,
                    ]
                );
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $types
     * @param  array{at: string, source: string, id: int}|null  $cursor
     * @return array<int, array<string, mixed>>
     */
    private function activityLogs(
        Customer $customer,
        int $accountId,
        array $types,
        array $capabilities,
        ?Carbon $fromUtc,
        ?Carbon $toUtc,
        ?array $cursor,
        int $limit
    ): array {
        $noteActions = self::NOTE_ACTIONS;
        $communicationActions = array_values(array_unique(array_merge(
            MessageEventTaxonomy::actions(),
            MeetingEventTaxonomy::actions(),
            array_values(array_diff(SalesActivityTaxonomy::actions(), $noteActions))
        )));
        $allowedActions = [];
        if (in_array('notes', $types, true)) {
            $allowedActions = array_merge($allowedActions, $noteActions);
        }
        if (in_array('communications', $types, true)) {
            $allowedActions = array_merge($allowedActions, $communicationActions);
        }
        if (in_array('profile_changes', $types, true)) {
            $allowedActions = array_merge($allowedActions, self::PROFILE_ACTIONS);
        }
        $allowedActions = array_values(array_unique($allowedActions));
        $relatedActions = array_values(array_unique(array_merge($noteActions, $communicationActions)));
        $leadIds = ($capabilities['requests'] ?? false)
            ? LeadRequest::query()
                ->where('user_id', $accountId)
                ->where('customer_id', $customer->id)
                ->pluck('id')
            : collect();
        $quoteIds = ($capabilities['quotes'] ?? false)
            ? Quote::query()
                ->where('user_id', $accountId)
                ->where('customer_id', $customer->id)
                ->pluck('id')
            : collect();

        $query = ActivityLog::query()
            ->whereIn('action', $allowedActions)
            ->where(function (Builder $subjectQuery) use ($customer, $leadIds, $quoteIds, $relatedActions): void {
                $subjectQuery->where(function (Builder $customerQuery) use ($customer): void {
                    $customerQuery->where('subject_type', Customer::class)
                        ->where('subject_id', $customer->id);
                });

                if ($leadIds->isNotEmpty()) {
                    $subjectQuery->orWhere(function (Builder $leadQuery) use ($leadIds, $relatedActions): void {
                        $leadQuery->where('subject_type', LeadRequest::class)
                            ->whereIn('subject_id', $leadIds)
                            ->whereIn('action', $relatedActions);
                    });
                }

                if ($quoteIds->isNotEmpty()) {
                    $subjectQuery->orWhere(function (Builder $quoteQuery) use ($quoteIds, $relatedActions): void {
                        $quoteQuery->where('subject_type', Quote::class)
                            ->whereIn('subject_id', $quoteIds)
                            ->whereIn('action', $relatedActions);
                    });
                }
            })
            ->with('user:id,name');
        $this->applyDateBounds($query, 'created_at', $fromUtc, $toUtc);
        $this->applyCursor($query, 'created_at', self::SOURCE_ACTIVITY, $cursor);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get(['id', 'user_id', 'action', 'description', 'properties', 'subject_type', 'subject_id', 'created_at'])
            ->map(function (ActivityLog $log) use ($capabilities, $types): ?array {
                $type = $this->activityType((string) $log->action);
                if (! in_array($type, $types, true)) {
                    return null;
                }
                $properties = (array) ($log->properties ?? []);
                if (! ($capabilities['notes'] ?? false)) {
                    unset($properties['note']);
                    foreach (['before', 'after', 'changes'] as $propertyGroup) {
                        if (is_array($properties[$propertyGroup] ?? null)) {
                            unset($properties[$propertyGroup]['description']);
                        }
                    }
                }

                return $this->event(
                    self::SOURCE_ACTIVITY,
                    (int) $log->id,
                    $log->created_at,
                    $type,
                    $this->activityStatus((string) $log->action, $properties),
                    $this->activityTitle($log, $type),
                    $this->activityDescription($log, $properties),
                    null,
                    null,
                    $this->activityIcon($log, $type),
                    $log->user ? [
                        'id' => (int) $log->user->id,
                        'name' => (string) $log->user->name,
                    ] : null,
                    [
                        'action' => $log->action,
                        'changes' => $properties['changes'] ?? null,
                        'before' => $properties['before'] ?? null,
                        'after' => $properties['after'] ?? null,
                        'sales_activity' => SalesActivityTaxonomy::present($log->action, $properties),
                        'message_event' => MessageEventTaxonomy::present($log->action, $properties),
                        'meeting_event' => MeetingEventTaxonomy::present($log->action, $properties),
                    ]
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function applyDateBounds(Builder $query, string $dateSql, ?Carbon $fromUtc, ?Carbon $toUtc): void
    {
        if ($fromUtc) {
            $query->whereRaw($dateSql.' >= ?', [$fromUtc->toDateTimeString()]);
        }
        if ($toUtc) {
            $query->whereRaw($dateSql.' < ?', [$toUtc->toDateTimeString()]);
        }
    }

    /** @param array{at: string, source: string, id: int}|null $cursor */
    private function applyCursor(Builder $query, string $dateSql, string $source, ?array $cursor): void
    {
        if (! $cursor) {
            return;
        }

        $cursorAt = $cursor['at'];
        $cursorSource = $cursor['source'];
        $cursorId = $cursor['id'];
        $query->where(function (Builder $cursorQuery) use ($dateSql, $source, $cursorAt, $cursorSource, $cursorId): void {
            $cursorQuery->whereRaw($dateSql.' < ?', [$cursorAt]);

            if (strcmp($source, $cursorSource) < 0) {
                $cursorQuery->orWhereRaw($dateSql.' = ?', [$cursorAt]);
            } elseif ($source === $cursorSource) {
                $cursorQuery->orWhere(function (Builder $sameSource) use ($dateSql, $cursorAt, $cursorId): void {
                    $sameSource->whereRaw($dateSql.' = ?', [$cursorAt])
                        ->where('id', '<', $cursorId);
                });
            }
        });
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null, 2: string|null, 3: string|null}
     */
    private function periodBounds(string $period, mixed $from, mixed $to, string $timezone): array
    {
        $now = Carbon::now($timezone);
        if ($period === 'all') {
            return [null, null, null, null];
        }

        if ($period === 'custom') {
            $fromLocal = Carbon::createFromFormat('Y-m-d', (string) $from, $timezone)->startOfDay();
            $toLocal = Carbon::createFromFormat('Y-m-d', (string) $to, $timezone)->addDay()->startOfDay();

            return [
                $fromLocal->copy()->utc(),
                $toLocal->copy()->utc(),
                $fromLocal->toDateString(),
                $toLocal->copy()->subDay()->toDateString(),
            ];
        }

        $rollingStart = match ($period) {
            'last_7_days' => $now->copy()->subDays(6)->startOfDay(),
            'last_30_days' => $now->copy()->subDays(29)->startOfDay(),
            'last_6_months' => $now->copy()->subMonthsNoOverflow(6)->startOfDay(),
            'last_90_days' => $now->copy()->subDays(89)->startOfDay(),
            default => null,
        };
        if ($rollingStart) {
            // Rolling presets keep the upper bound open so upcoming
            // appointments remain visible beside the recent history.
            return [
                $rollingStart->copy()->utc(),
                null,
                $rollingStart->toDateString(),
                null,
            ];
        }

        [$fromLocal, $toLocal] = match ($period) {
            'current_year' => [$now->copy()->startOfYear(), $now->copy()->addYear()->startOfYear()],
            'previous_year' => [$now->copy()->subYear()->startOfYear(), $now->copy()->startOfYear()],
            default => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->addDay()->startOfDay()],
        };

        return [
            $fromLocal->copy()->utc(),
            $toLocal->copy()->utc(),
            $fromLocal->toDateString(),
            $toLocal->copy()->subDay()->toDateString(),
        ];
    }

    private function timezone(User $accountOwner): string
    {
        $timezone = trim((string) ($accountOwner->company_timezone ?: config('app.timezone', 'UTC')));

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /** @return array{at: string, source: string, id: int}|null */
    private function decodeCursor(mixed $cursor): ?array
    {
        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => 'The activity cursor is invalid.']);
        }

        if (! is_array($decoded)
            || ! is_string($decoded['at'] ?? null)
            || ! in_array($decoded['source'] ?? null, [
                self::SOURCE_ACTIVITY,
                self::SOURCE_CAMPAIGN,
                self::SOURCE_INVOICE,
                self::SOURCE_PAYMENT,
                self::SOURCE_RESERVATION,
            ], true)
            || ! is_int($decoded['id'] ?? null)
            || $decoded['id'] < 1) {
            throw ValidationException::withMessages(['cursor' => 'The activity cursor is invalid.']);
        }

        try {
            $decoded['at'] = Carbon::parse($decoded['at'])->utc()->toDateTimeString();
        } catch (Throwable) {
            throw ValidationException::withMessages(['cursor' => 'The activity cursor is invalid.']);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $event */
    private function encodeCursor(array $event): string
    {
        return Crypt::encryptString(json_encode([
            'at' => $event['_sort_at'],
            'source' => $event['_source'],
            'id' => $event['_source_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array{at: string, source: string, id: int}|null  $cursor
     */
    private function isAfterCursor(array $event, ?array $cursor): bool
    {
        if (! $cursor) {
            return true;
        }

        return $event['_sort_at'] < $cursor['at']
            || ($event['_sort_at'] === $cursor['at'] && $event['_source'] < $cursor['source'])
            || ($event['_sort_at'] === $cursor['at']
                && $event['_source'] === $cursor['source']
                && $event['_source_id'] < $cursor['id']);
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareEvents(array $left, array $right): int
    {
        return [$right['_sort_at'], $right['_source'], $right['_source_id']]
            <=> [$left['_sort_at'], $left['_source'], $left['_source_id']];
    }

    /**
     * @param  array<string, mixed>|null  $amount
     * @param  array<string, mixed>|null  $resource
     * @param  array<string, mixed>|null  $actor
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function event(
        string $source,
        int $sourceId,
        mixed $occurredAt,
        string $type,
        string $status,
        string $title,
        ?string $description,
        ?array $amount,
        ?array $resource,
        string $iconKey,
        ?array $actor,
        array $metadata
    ): array {
        $date = Carbon::parse($occurredAt)->utc();

        return [
            'id' => $source.':'.$sourceId,
            'occurred_at' => $date->toIso8601String(),
            'type' => $type,
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'amount' => $amount,
            'resource' => $resource,
            'icon_key' => $iconKey,
            'actor' => $actor,
            'metadata' => array_filter($metadata, fn (mixed $value): bool => $value !== null),
            '_sort_at' => $date->toDateTimeString(),
            '_source' => $source,
            '_source_id' => $sourceId,
        ];
    }

    private function appointmentStatus(Reservation $reservation): string
    {
        if (in_array($reservation->status, Reservation::STATUSES, true)) {
            return (string) $reservation->status;
        }

        return $reservation->starts_at?->isFuture() ? 'upcoming' : 'past';
    }

    private function appointmentTitle(string $status): string
    {
        return match ($status) {
            Reservation::STATUS_COMPLETED => 'Appointment completed',
            Reservation::STATUS_CANCELLED => 'Appointment cancelled',
            Reservation::STATUS_NO_SHOW => 'Appointment no-show',
            Reservation::STATUS_CONFIRMED => 'Appointment confirmed',
            Reservation::STATUS_RESCHEDULED => 'Appointment rescheduled',
            Reservation::STATUS_PENDING => 'Appointment pending',
            default => 'Appointment',
        };
    }

    private function appointmentIcon(string $status): string
    {
        return match ($status) {
            Reservation::STATUS_COMPLETED => 'calendar-check',
            Reservation::STATUS_CANCELLED => 'calendar-x',
            Reservation::STATUS_NO_SHOW => 'user-minus',
            default => 'calendar',
        };
    }

    private function paymentTitle(string $status): string
    {
        return match ($status) {
            Payment::STATUS_REFUNDED => 'Payment refunded',
            Payment::STATUS_REVERSED => 'Payment reversed',
            Payment::STATUS_FAILED => 'Payment failed',
            Payment::STATUS_PENDING => 'Payment pending',
            default => 'Payment received',
        };
    }

    private function campaignEventTitle(string $eventType): string
    {
        return match ($eventType) {
            'queued' => 'Campaign message queued',
            'sent' => 'Campaign message sent',
            'delivered' => 'Campaign message delivered',
            'opened' => 'Campaign message opened',
            'click', 'clicked' => 'Campaign link clicked',
            'conversion', 'converted' => 'Campaign conversion recorded',
            'failed' => 'Campaign message failed',
            'skipped' => 'Campaign message skipped',
            'unsubscribe', 'unsubscribed' => 'Customer unsubscribed',
            default => 'Campaign activity',
        };
    }

    private function activityType(string $action): string
    {
        if (in_array($action, self::NOTE_ACTIONS, true)) {
            return 'notes';
        }
        if (MessageEventTaxonomy::isMessageEvent($action)
            || MeetingEventTaxonomy::isMeetingEvent($action)
            || SalesActivityTaxonomy::isSalesActivity($action)) {
            return 'communications';
        }

        return 'profile_changes';
    }

    /** @param array<string, mixed> $properties */
    private function activityStatus(string $action, array $properties): string
    {
        $profileStatus = match ($action) {
            'customer_archived' => 'inactive',
            'customer_restored' => 'active',
            'portal_access_enabled' => 'active',
            'portal_access_disabled' => 'inactive',
            'customer_vip_updated', 'customer_vip_auto_synced' => (bool) data_get($properties, 'after.is_vip')
                ? 'vip'
                : 'standard',
            default => null,
        };

        return (string) ($properties['status']
            ?? $properties['after']['status']
            ?? $profileStatus
            ?? MessageEventTaxonomy::present($action, $properties)['delivery_state']
            ?? MeetingEventTaxonomy::present($action, $properties)['lifecycle_state']
            ?? SalesActivityTaxonomy::present($action, $properties)['outcome']
            ?? 'recorded');
    }

    private function activityTitle(ActivityLog $log, string $type): string
    {
        $properties = (array) ($log->properties ?? []);
        $presented = match ($type) {
            'communications' => MessageEventTaxonomy::present($log->action, $properties)
                ?? MeetingEventTaxonomy::present($log->action, $properties)
                ?? SalesActivityTaxonomy::present($log->action, $properties),
            default => null,
        };

        return (string) ($presented['label'] ?? Str::headline((string) $log->action));
    }

    /** @param array<string, mixed> $properties */
    private function activityDescription(ActivityLog $log, array $properties): ?string
    {
        $description = trim((string) ($properties['note'] ?? $log->description ?? ''));
        $changedFields = collect(array_keys((array) ($properties['changes'] ?? [])))
            ->map(fn (string $field): string => Str::headline($field))
            ->implode(', ');

        if ($changedFields !== '' && ! isset($properties['note'])) {
            $description = $description !== ''
                ? $description.' · '.$changedFields
                : $changedFields;
        }

        return $description !== '' ? $description : null;
    }

    private function activityIcon(ActivityLog $log, string $type): string
    {
        $properties = (array) ($log->properties ?? []);
        if ($type === 'communications') {
            $presented = MessageEventTaxonomy::present($log->action, $properties)
                ?? MeetingEventTaxonomy::present($log->action, $properties)
                ?? SalesActivityTaxonomy::present($log->action, $properties);

            return (string) ($presented['icon'] ?? 'chat-bubble-left-right');
        }

        return $type === 'notes' ? 'note' : 'user-circle';
    }
}
