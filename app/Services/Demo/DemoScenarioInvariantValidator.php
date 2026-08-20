<?php

namespace App\Services\Demo;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DemoScenarioInvariantValidator
{
    private const MONEY_EPSILON = 0.009;

    /**
     * @return array<string, mixed>
     */
    public function validate(User $owner, CarbonInterface|string $referenceDate): array
    {
        $reference = $this->normalizeReferenceDate($owner, $referenceDate);
        $dayStart = $reference->startOfDay()->utc();
        $dayEnd = $reference->endOfDay()->utc();
        $context = $this->loadContext($owner);

        $checks = [
            'tenant_relations' => $this->validateTenantRelations($owner, $context),
            'completed_reservations_are_past' => $this->validateCompletedReservations($owner, $context, $dayStart),
            'future_reservations_are_not_completed' => $this->validateFutureReservations($owner, $context, $dayEnd),
            'active_reservations_do_not_overlap' => $this->validateReservationOverlaps($owner, $context),
            'reservation_service_timing_matches' => $this->validateReservationServiceTiming($owner, $context),
            'paid_invoices_have_zero_balance' => $this->validatePaidInvoices($owner, $context),
            'partial_invoices_have_payment_and_balance' => $this->validatePartialInvoices($owner, $context),
            'stock_is_non_negative' => $this->validateStock($context),
            'dates_are_coherent' => $this->validateDates($owner, $context, $dayEnd),
            'twelve_month_coverage' => $this->validateCoverage($owner, $context, $reference),
        ];

        $violations = collect($checks)
            ->flatMap(fn (array $check): array => $check['violations'])
            ->values()
            ->all();
        $failedChecks = collect($checks)
            ->filter(fn (array $check): bool => ! $check['valid'])
            ->keys()
            ->values()
            ->all();

        return [
            'valid' => $violations === [],
            'owner_id' => $owner->getKey(),
            'reference_date' => $reference->toDateString(),
            'timezone' => $reference->getTimezone()->getName(),
            'boundaries' => [
                'day_start_utc' => $dayStart->toIso8601String(),
                'day_end_utc' => $dayEnd->toIso8601String(),
            ],
            'summary' => [
                'check_count' => count($checks),
                'passed_check_count' => count($checks) - count($failedChecks),
                'failed_check_count' => count($failedChecks),
                'failed_checks' => $failedChecks,
                'violation_count' => count($violations),
            ],
            'checks' => $checks,
            'violations' => $violations,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DemoScenarioInvariantViolationException
     */
    public function validateOrFail(User $owner, CarbonInterface|string $referenceDate): array
    {
        $report = $this->validate($owner, $referenceDate);

        if (! $report['valid']) {
            throw new DemoScenarioInvariantViolationException($report);
        }

        return $report;
    }

    private function normalizeReferenceDate(User $owner, CarbonInterface|string $referenceDate): CarbonImmutable
    {
        $timezone = filled($owner->company_timezone)
            ? (string) $owner->company_timezone
            : (string) config('app.timezone', 'UTC');

        if ($referenceDate instanceof CarbonInterface) {
            return CarbonImmutable::instance($referenceDate)->setTimezone($timezone);
        }

        return CarbonImmutable::parse($referenceDate, $timezone);
    }

    /**
     * @return array<string, Collection<int, mixed>>
     */
    private function loadContext(User $owner): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $customers = Customer::query()
            ->where('user_id', $ownerId)
            ->get(['id', 'user_id', 'created_at']);
        $customerIds = $customers->pluck('id')->all();

        $reservations = Reservation::query()
            ->where(function (Builder $query) use ($ownerId, $customerIds): void {
                $query->where('account_id', $ownerId);

                if ($customerIds !== []) {
                    $query->orWhereIn('client_id', $customerIds);
                }
            })
            ->with([
                'client:id,user_id,created_at',
                'teamMember:id,account_id',
                'service:id,user_id,tags',
            ])
            ->get([
                'id',
                'account_id',
                'team_member_id',
                'client_id',
                'service_id',
                'status',
                'starts_at',
                'ends_at',
                'duration_minutes',
                'buffer_minutes',
                'metadata',
                'created_at',
            ]);

        $invoices = Invoice::query()
            ->where(function (Builder $query) use ($ownerId, $customerIds): void {
                $query->where('user_id', $ownerId);

                if ($customerIds !== []) {
                    $query->orWhereIn('customer_id', $customerIds);
                }
            })
            ->with([
                'customer:id,user_id,created_at',
                'payments:id,invoice_id,customer_id,user_id,status,amount,paid_at,created_at',
            ])
            ->get(['id', 'user_id', 'customer_id', 'status', 'total', 'created_at']);
        $ownedInvoiceIds = $invoices
            ->where('user_id', $ownerId)
            ->pluck('id')
            ->all();

        $payments = Payment::query()
            ->where(function (Builder $query) use ($ownerId, $customerIds, $ownedInvoiceIds): void {
                $query->where('user_id', $ownerId);

                if ($customerIds !== []) {
                    $query->orWhereIn('customer_id', $customerIds);
                }

                if ($ownedInvoiceIds !== []) {
                    $query->orWhereIn('invoice_id', $ownedInvoiceIds);
                }
            })
            ->with([
                'customer:id,user_id,created_at',
                'invoice:id,user_id,customer_id,created_at',
                'sale:id,user_id,customer_id,created_at',
            ])
            ->get([
                'id',
                'invoice_id',
                'sale_id',
                'customer_id',
                'user_id',
                'status',
                'amount',
                'paid_at',
                'created_at',
            ]);

        $products = Product::query()
            ->byUser($ownerId)
            ->products()
            ->with([
                'inventories:id,product_id,warehouse_id,on_hand,reserved,damaged,minimum_stock,reorder_point,created_at',
                'inventories.warehouse:id,user_id',
            ])
            ->get(['id', 'user_id', 'stock', 'created_at']);
        $productIds = $products->pluck('id')->all();
        $stockMovements = $productIds === []
            ? collect()
            : ProductStockMovement::query()
                ->whereIn('product_id', $productIds)
                ->get(['id', 'product_id', 'created_at']);

        $tasks = Task::query()
            ->where(function (Builder $query) use ($ownerId, $customerIds): void {
                $query->where('account_id', $ownerId);

                if ($customerIds !== []) {
                    $query->orWhereIn('customer_id', $customerIds);
                }
            })
            ->with([
                'customer:id,user_id,created_at',
                'assignee:id,account_id',
                'product:id,user_id',
            ])
            ->get([
                'id',
                'account_id',
                'assigned_team_member_id',
                'customer_id',
                'product_id',
                'status',
                'due_date',
                'created_at',
            ]);
        $transactions = Transaction::query()
            ->where(function (Builder $query) use ($ownerId, $customerIds): void {
                $query->where('user_id', $ownerId);

                if ($customerIds !== []) {
                    $query->orWhereIn('customer_id', $customerIds);
                }
            })
            ->with([
                'customer:id,user_id,created_at',
                'quote:id,user_id,customer_id,created_at',
            ])
            ->get([
                'id',
                'quote_id',
                'customer_id',
                'user_id',
                'status',
                'paid_at',
                'created_at',
            ]);

        return compact(
            'customers',
            'reservations',
            'invoices',
            'payments',
            'products',
            'stockMovements',
            'tasks',
            'transactions',
        );
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateTenantRelations(User $owner, array $context): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = [];

        if (! $owner->exists || ! $owner->getKey()) {
            $violations[] = $this->violation(
                'owner.not_persisted',
                'user',
                $owner->getKey(),
                'The scenario owner must be persisted before validation.'
            );
        }

        foreach ($context['reservations'] as $reservation) {
            if ((int) $reservation->account_id !== $ownerId) {
                $violations[] = $this->violation(
                    'reservation.account_mismatch',
                    'reservation',
                    $reservation->id,
                    'The reservation is linked to a customer in this scenario but belongs to another tenant.',
                    ['account_id' => $reservation->account_id, 'expected_account_id' => $ownerId]
                );
            }

            if (! $reservation->client_id || ! $reservation->client) {
                $violations[] = $this->violation(
                    'reservation.client_missing',
                    'reservation',
                    $reservation->id,
                    'The reservation must reference an existing scenario customer.'
                );
            } elseif ((int) $reservation->client->user_id !== $ownerId) {
                $violations[] = $this->violation(
                    'reservation.client_tenant_mismatch',
                    'reservation',
                    $reservation->id,
                    'The reservation customer belongs to another tenant.',
                    ['customer_id' => $reservation->client_id]
                );
            }

            if (! $reservation->teamMember || (int) $reservation->teamMember->account_id !== $ownerId) {
                $violations[] = $this->violation(
                    'reservation.team_member_tenant_mismatch',
                    'reservation',
                    $reservation->id,
                    'The assigned team member does not belong to the scenario tenant.',
                    ['team_member_id' => $reservation->team_member_id]
                );
            }

            if ($reservation->service_id && (! $reservation->service || (int) $reservation->service->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'reservation.service_tenant_mismatch',
                    'reservation',
                    $reservation->id,
                    'The reservation service does not belong to the scenario tenant.',
                    ['service_id' => $reservation->service_id]
                );
            }
        }

        foreach ($context['invoices'] as $invoice) {
            if ((int) $invoice->user_id !== $ownerId) {
                $violations[] = $this->violation(
                    'invoice.account_mismatch',
                    'invoice',
                    $invoice->id,
                    'The invoice is linked to a scenario customer but belongs to another tenant.',
                    ['user_id' => $invoice->user_id, 'expected_user_id' => $ownerId]
                );
            }

            if (! $invoice->customer || (int) $invoice->customer->user_id !== $ownerId) {
                $violations[] = $this->violation(
                    'invoice.customer_tenant_mismatch',
                    'invoice',
                    $invoice->id,
                    'The invoice customer does not belong to the scenario tenant.',
                    ['customer_id' => $invoice->customer_id]
                );
            }
        }

        foreach ($context['payments'] as $payment) {
            if ((int) $payment->user_id !== $ownerId) {
                $violations[] = $this->violation(
                    'payment.account_mismatch',
                    'payment',
                    $payment->id,
                    'The payment does not belong to the scenario tenant.',
                    ['user_id' => $payment->user_id, 'expected_user_id' => $ownerId]
                );
            }

            if ($payment->customer_id && (! $payment->customer || (int) $payment->customer->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'payment.customer_tenant_mismatch',
                    'payment',
                    $payment->id,
                    'The payment customer does not belong to the scenario tenant.',
                    ['customer_id' => $payment->customer_id]
                );
            }

            if ($payment->invoice_id && (! $payment->invoice || (int) $payment->invoice->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'payment.invoice_tenant_mismatch',
                    'payment',
                    $payment->id,
                    'The payment invoice does not belong to the scenario tenant.',
                    ['invoice_id' => $payment->invoice_id]
                );
            }

            if ($payment->sale_id && (! $payment->sale || (int) $payment->sale->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'payment.sale_tenant_mismatch',
                    'payment',
                    $payment->id,
                    'The payment sale does not belong to the scenario tenant.',
                    ['sale_id' => $payment->sale_id]
                );
            }

            if ($payment->invoice && $payment->customer_id && (int) $payment->invoice->customer_id !== (int) $payment->customer_id) {
                $violations[] = $this->violation(
                    'payment.invoice_customer_mismatch',
                    'payment',
                    $payment->id,
                    'The payment customer differs from the invoice customer.',
                    [
                        'customer_id' => $payment->customer_id,
                        'invoice_customer_id' => $payment->invoice->customer_id,
                    ]
                );
            }
        }

        foreach ($context['tasks'] as $task) {
            if ((int) $task->account_id !== $ownerId) {
                $violations[] = $this->violation(
                    'task.account_mismatch',
                    'task',
                    $task->id,
                    'The task belongs to another tenant.',
                    ['account_id' => $task->account_id, 'expected_account_id' => $ownerId],
                );
            }

            if ($task->customer_id && (! $task->customer || (int) $task->customer->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'task.customer_tenant_mismatch',
                    'task',
                    $task->id,
                    'The task customer belongs to another tenant.',
                    ['customer_id' => $task->customer_id],
                );
            }

            if ($task->assigned_team_member_id && (! $task->assignee || (int) $task->assignee->account_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'task.assignee_tenant_mismatch',
                    'task',
                    $task->id,
                    'The task assignee belongs to another tenant.',
                    ['team_member_id' => $task->assigned_team_member_id],
                );
            }

            if ($task->product_id && (! $task->product || (int) $task->product->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'task.product_tenant_mismatch',
                    'task',
                    $task->id,
                    'The task service belongs to another tenant.',
                    ['product_id' => $task->product_id],
                );
            }
        }

        foreach ($context['transactions'] as $transaction) {
            if ((int) $transaction->user_id !== $ownerId) {
                $violations[] = $this->violation(
                    'transaction.account_mismatch',
                    'transaction',
                    $transaction->id,
                    'The transaction belongs to another tenant.',
                    ['user_id' => $transaction->user_id, 'expected_user_id' => $ownerId],
                );
            }

            if (! $transaction->customer || (int) $transaction->customer->user_id !== $ownerId) {
                $violations[] = $this->violation(
                    'transaction.customer_tenant_mismatch',
                    'transaction',
                    $transaction->id,
                    'The transaction customer belongs to another tenant.',
                    ['customer_id' => $transaction->customer_id],
                );
            }

            if ($transaction->quote_id && (! $transaction->quote || (int) $transaction->quote->user_id !== $ownerId)) {
                $violations[] = $this->violation(
                    'transaction.quote_tenant_mismatch',
                    'transaction',
                    $transaction->id,
                    'The transaction quote belongs to another tenant.',
                    ['quote_id' => $transaction->quote_id],
                );
            }

            if ($transaction->quote && (int) $transaction->quote->customer_id !== (int) $transaction->customer_id) {
                $violations[] = $this->violation(
                    'transaction.quote_customer_mismatch',
                    'transaction',
                    $transaction->id,
                    'The transaction customer differs from the quote customer.',
                    [
                        'customer_id' => $transaction->customer_id,
                        'quote_customer_id' => $transaction->quote->customer_id,
                    ],
                );
            }
        }

        return $this->check($violations, [
            'entity_counts' => [
                'customers' => $context['customers']->count(),
                'reservations' => $context['reservations']->count(),
                'invoices' => $context['invoices']->count(),
                'payments' => $context['payments']->count(),
                'tasks' => $context['tasks']->count(),
                'transactions' => $context['transactions']->count(),
            ],
        ]);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateCompletedReservations(User $owner, array $context, CarbonImmutable $dayStart): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = $context['reservations']
            ->where('account_id', $ownerId)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->filter(fn (Reservation $reservation): bool => ! $reservation->ends_at || ! $reservation->ends_at->lt($dayStart))
            ->map(fn (Reservation $reservation): array => $this->violation(
                'reservation.completed_not_past',
                'reservation',
                $reservation->id,
                'A completed reservation must end before the reference date.',
                [
                    'status' => $reservation->status,
                    'starts_at' => $reservation->starts_at?->toIso8601String(),
                    'ends_at' => $reservation->ends_at?->toIso8601String(),
                ]
            ))
            ->values()
            ->all();

        return $this->check($violations, [
            'reference_day_start_utc' => $dayStart->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateFutureReservations(User $owner, array $context, CarbonImmutable $dayEnd): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = $context['reservations']
            ->where('account_id', $ownerId)
            ->filter(fn (Reservation $reservation): bool => $reservation->starts_at?->gt($dayEnd) === true)
            ->where('status', Reservation::STATUS_COMPLETED)
            ->map(fn (Reservation $reservation): array => $this->violation(
                'reservation.future_completed',
                'reservation',
                $reservation->id,
                'A future reservation cannot be completed.',
                [
                    'status' => $reservation->status,
                    'starts_at' => $reservation->starts_at?->toIso8601String(),
                ]
            ))
            ->values()
            ->all();

        return $this->check($violations, [
            'reference_day_end_utc' => $dayEnd->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateReservationOverlaps(User $owner, array $context): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = [];
        $reservationsByMember = $context['reservations']
            ->where('account_id', $ownerId)
            ->whereIn('status', [
                ...Reservation::ACTIVE_STATUSES,
                Reservation::STATUS_COMPLETED,
                Reservation::STATUS_NO_SHOW,
            ])
            ->filter(fn (Reservation $reservation): bool => $reservation->team_member_id !== null
                && $reservation->starts_at !== null
                && $reservation->ends_at !== null)
            ->groupBy('team_member_id');

        foreach ($reservationsByMember as $teamMemberId => $reservations) {
            $candidates = [];

            foreach ($reservations->sortBy('starts_at') as $reservation) {
                $reservationBuffer = max(0, (int) $reservation->buffer_minutes);
                $candidates = array_values(array_filter(
                    $candidates,
                    fn (Reservation $candidate): bool => $candidate->ends_at
                        ->copy()
                        ->addMinutes(max($reservationBuffer, max(0, (int) $candidate->buffer_minutes)))
                        ->gt($reservation->starts_at)
                ));

                foreach ($candidates as $candidate) {
                    $effectiveBuffer = max(
                        $reservationBuffer,
                        max(0, (int) $candidate->buffer_minutes)
                    );
                    $blockedEnd = $candidate->ends_at->copy()->addMinutes($effectiveBuffer);

                    if (! $reservation->starts_at->lt($blockedEnd)) {
                        continue;
                    }

                    $violations[] = $this->violation(
                        'reservation.active_overlap',
                        'team_member',
                        $teamMemberId,
                        'Active reservations overlap for the same team member.',
                        [
                            'reservation_ids' => [$candidate->id, $reservation->id],
                            'effective_buffer_minutes' => $effectiveBuffer,
                            'first_ends_at' => $candidate->ends_at?->toIso8601String(),
                            'second_starts_at' => $reservation->starts_at?->toIso8601String(),
                        ]
                    );
                }

                $candidates[] = $reservation;
            }
        }

        return $this->check($violations);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateReservationServiceTiming(User $owner, array $context): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = [];

        foreach ($context['reservations']->where('account_id', $ownerId) as $reservation) {
            $metadata = (array) $reservation->metadata;
            $metadataServiceKey = trim((string) ($metadata['service_key'] ?? ''));
            if ($metadataServiceKey === '' || ! $reservation->service) {
                continue;
            }

            $timing = collect((array) $reservation->service->tags)
                ->filter(fn (mixed $tag): bool => is_string($tag) && str_contains($tag, ':'))
                ->mapWithKeys(function (string $tag): array {
                    [$key, $value] = explode(':', $tag, 2);

                    return [$key => $value];
                });
            $serviceKey = trim((string) $timing->get('key', ''));
            $expectedDuration = (int) $timing->get('duration', 0);
            $expectedBuffer = (int) $timing->get('buffer-after', 0);
            $actualElapsed = $reservation->starts_at && $reservation->ends_at
                ? (int) round($reservation->starts_at->diffInMinutes($reservation->ends_at, false))
                : 0;

            if (
                $serviceKey !== $metadataServiceKey
                || $expectedDuration <= 0
                || (int) $reservation->duration_minutes !== $expectedDuration
                || $actualElapsed !== $expectedDuration
                || (int) $reservation->buffer_minutes !== $expectedBuffer
            ) {
                $violations[] = $this->violation(
                    'reservation.service_timing_mismatch',
                    'reservation',
                    $reservation->id,
                    'Reservation timing and metadata must match the assigned service definition.',
                    [
                        'service_key' => $serviceKey,
                        'metadata_service_key' => $metadataServiceKey,
                        'expected_duration_minutes' => $expectedDuration,
                        'duration_minutes' => (int) $reservation->duration_minutes,
                        'elapsed_minutes' => $actualElapsed,
                        'expected_buffer_minutes' => $expectedBuffer,
                        'buffer_minutes' => (int) $reservation->buffer_minutes,
                    ],
                );
            }
        }

        return $this->check($violations);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validatePaidInvoices(User $owner, array $context): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = $context['invoices']
            ->where('user_id', $ownerId)
            ->where('status', 'paid')
            ->filter(fn (Invoice $invoice): bool => abs($invoice->balance_due) > self::MONEY_EPSILON)
            ->map(fn (Invoice $invoice): array => $this->violation(
                'invoice.paid_balance_non_zero',
                'invoice',
                $invoice->id,
                'A paid invoice must have a zero balance.',
                [
                    'total' => (float) $invoice->total,
                    'amount_paid' => $invoice->amount_paid,
                    'balance_due' => $invoice->balance_due,
                ]
            ))
            ->values()
            ->all();

        return $this->check($violations);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validatePartialInvoices(User $owner, array $context): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = [];

        foreach ($context['invoices']->where('user_id', $ownerId)->where('status', 'partial') as $invoice) {
            $settledPayments = $invoice->payments->whereIn('status', Payment::settledStatuses());

            if ($settledPayments->isNotEmpty() && $invoice->balance_due > self::MONEY_EPSILON) {
                continue;
            }

            $violations[] = $this->violation(
                'invoice.partial_payment_or_balance_invalid',
                'invoice',
                $invoice->id,
                'A partial invoice must have at least one settled payment and a positive balance.',
                [
                    'settled_payment_count' => $settledPayments->count(),
                    'amount_paid' => $invoice->amount_paid,
                    'balance_due' => $invoice->balance_due,
                ]
            );
        }

        return $this->check($violations);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateStock(array $context): array
    {
        $violations = [];

        foreach ($context['products'] as $product) {
            if ((int) $product->stock < 0) {
                $violations[] = $this->violation(
                    'product.stock_negative',
                    'product',
                    $product->id,
                    'Product stock cannot be negative.',
                    ['stock' => (int) $product->stock]
                );
            }

            foreach ($product->inventories as $inventory) {
                foreach (['on_hand', 'reserved', 'damaged'] as $field) {
                    if ((int) $inventory->{$field} >= 0) {
                        continue;
                    }

                    $violations[] = $this->violation(
                        "inventory.{$field}_negative",
                        'product_inventory',
                        $inventory->id,
                        "Inventory {$field} cannot be negative.",
                        ['product_id' => $product->id, $field => (int) $inventory->{$field}]
                    );
                }

                $rawAvailable = (int) $inventory->on_hand - (int) $inventory->reserved;
                if ($rawAvailable < 0) {
                    $violations[] = $this->violation(
                        'inventory.available_negative',
                        'product_inventory',
                        $inventory->id,
                        'Available inventory cannot be negative.',
                        [
                            'product_id' => $product->id,
                            'on_hand' => (int) $inventory->on_hand,
                            'reserved' => (int) $inventory->reserved,
                            'available' => $rawAvailable,
                        ]
                    );
                }

                if (! $inventory->warehouse || (int) $inventory->warehouse->user_id !== (int) $product->user_id) {
                    $violations[] = $this->violation(
                        'inventory.warehouse_tenant_mismatch',
                        'product_inventory',
                        $inventory->id,
                        'The inventory warehouse does not belong to the product tenant.',
                        ['product_id' => $product->id, 'warehouse_id' => $inventory->warehouse_id]
                    );
                }
            }
        }

        return $this->check($violations, [
            'product_count' => $context['products']->count(),
            'inventory_count' => $context['products']->sum(fn (Product $product): int => $product->inventories->count()),
        ]);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateDates(User $owner, array $context, CarbonImmutable $dayEnd): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $violations = [];

        foreach ($context['customers'] as $customer) {
            $this->appendFutureCreationViolation($violations, 'customer', $customer->id, $customer->created_at, $dayEnd);
        }

        foreach ($context['reservations']->where('account_id', $ownerId) as $reservation) {
            $this->appendFutureCreationViolation($violations, 'reservation', $reservation->id, $reservation->created_at, $dayEnd);

            if (! $reservation->starts_at || ! $reservation->ends_at || ! $reservation->ends_at->gt($reservation->starts_at)) {
                $violations[] = $this->violation(
                    'reservation.invalid_date_range',
                    'reservation',
                    $reservation->id,
                    'Reservation end time must be after its start time.',
                    [
                        'starts_at' => $reservation->starts_at?->toIso8601String(),
                        'ends_at' => $reservation->ends_at?->toIso8601String(),
                    ]
                );
            }

            if ($reservation->created_at && $reservation->starts_at && $reservation->created_at->gt($reservation->starts_at)) {
                $violations[] = $this->violation(
                    'reservation.created_after_start',
                    'reservation',
                    $reservation->id,
                    'A reservation cannot be created after it starts.',
                    [
                        'created_at' => $reservation->created_at->toIso8601String(),
                        'starts_at' => $reservation->starts_at->toIso8601String(),
                    ]
                );
            }

            if ($reservation->client?->created_at && $reservation->created_at && $reservation->client->created_at->gt($reservation->created_at)) {
                $violations[] = $this->violation(
                    'reservation.created_before_customer',
                    'reservation',
                    $reservation->id,
                    'A reservation cannot be created before its customer exists.',
                    [
                        'customer_id' => $reservation->client_id,
                        'customer_created_at' => $reservation->client->created_at->toIso8601String(),
                        'reservation_created_at' => $reservation->created_at->toIso8601String(),
                    ]
                );
            }

            if ((int) $reservation->duration_minutes <= 0) {
                $violations[] = $this->violation(
                    'reservation.duration_not_positive',
                    'reservation',
                    $reservation->id,
                    'Reservation duration must be positive.',
                    ['duration_minutes' => (int) $reservation->duration_minutes]
                );
            }
        }

        foreach ($context['invoices']->where('user_id', $ownerId) as $invoice) {
            $this->appendFutureCreationViolation($violations, 'invoice', $invoice->id, $invoice->created_at, $dayEnd);

            if ($invoice->customer?->created_at && $invoice->created_at && $invoice->customer->created_at->gt($invoice->created_at)) {
                $violations[] = $this->violation(
                    'invoice.created_before_customer',
                    'invoice',
                    $invoice->id,
                    'An invoice cannot be created before its customer exists.',
                    ['customer_id' => $invoice->customer_id]
                );
            }
        }

        foreach ($context['payments']->where('user_id', $ownerId) as $payment) {
            $this->appendFutureCreationViolation($violations, 'payment', $payment->id, $payment->created_at, $dayEnd);

            if ($payment->customer?->created_at && $payment->created_at && $payment->customer->created_at->gt($payment->created_at)) {
                $violations[] = $this->violation(
                    'payment.created_before_customer',
                    'payment',
                    $payment->id,
                    'A payment cannot be created before its customer exists.',
                    ['customer_id' => $payment->customer_id]
                );
            }
            if ($payment->invoice?->created_at && $payment->created_at && $payment->invoice->created_at->gt($payment->created_at)) {
                $violations[] = $this->violation(
                    'payment.created_before_invoice',
                    'payment',
                    $payment->id,
                    'An invoice payment cannot be created before its invoice exists.',
                    ['invoice_id' => $payment->invoice_id]
                );
            }
            if ($payment->sale?->created_at && $payment->created_at && $payment->sale->created_at->gt($payment->created_at)) {
                $violations[] = $this->violation(
                    'payment.created_before_sale',
                    'payment',
                    $payment->id,
                    'A sale payment cannot be created before its sale exists.',
                    ['sale_id' => $payment->sale_id]
                );
            }

            if (! in_array($payment->status, Payment::settledStatuses(), true)) {
                continue;
            }

            if (! $payment->paid_at) {
                $violations[] = $this->violation(
                    'payment.settled_without_paid_at',
                    'payment',
                    $payment->id,
                    'A settled payment must have a payment date.'
                );

                continue;
            }

            if ($payment->paid_at->gt($dayEnd)) {
                $violations[] = $this->violation(
                    'payment.paid_at_after_reference',
                    'payment',
                    $payment->id,
                    'A settled payment cannot be dated after the reference date.',
                    ['paid_at' => $payment->paid_at->toIso8601String()]
                );
            }

            if ($payment->created_at && $payment->paid_at->lt($payment->created_at)) {
                $violations[] = $this->violation(
                    'payment.paid_before_creation',
                    'payment',
                    $payment->id,
                    'A payment cannot be paid before it is created.',
                    [
                        'created_at' => $payment->created_at->toIso8601String(),
                        'paid_at' => $payment->paid_at->toIso8601String(),
                    ]
                );
            }
        }

        foreach ($context['tasks']->where('account_id', $ownerId) as $task) {
            $this->appendFutureCreationViolation($violations, 'task', $task->id, $task->created_at, $dayEnd);

            if ($task->customer?->created_at && $task->created_at && $task->customer->created_at->gt($task->created_at)) {
                $violations[] = $this->violation(
                    'task.created_before_customer',
                    'task',
                    $task->id,
                    'A task cannot be created before its customer exists.',
                    ['customer_id' => $task->customer_id],
                );
            }
        }

        foreach ($context['transactions']->where('user_id', $ownerId) as $transaction) {
            $this->appendFutureCreationViolation(
                $violations,
                'transaction',
                $transaction->id,
                $transaction->created_at,
                $dayEnd,
            );

            if ($transaction->customer?->created_at && $transaction->created_at && $transaction->customer->created_at->gt($transaction->created_at)) {
                $violations[] = $this->violation(
                    'transaction.created_before_customer',
                    'transaction',
                    $transaction->id,
                    'A transaction cannot be created before its customer exists.',
                    ['customer_id' => $transaction->customer_id],
                );
            }
            if ($transaction->quote?->created_at && $transaction->created_at && $transaction->quote->created_at->gt($transaction->created_at)) {
                $violations[] = $this->violation(
                    'transaction.created_before_quote',
                    'transaction',
                    $transaction->id,
                    'A quote deposit cannot be created before its quote exists.',
                    ['quote_id' => $transaction->quote_id],
                );
            }
            if ($transaction->paid_at && $transaction->created_at && $transaction->paid_at->lt($transaction->created_at)) {
                $violations[] = $this->violation(
                    'transaction.paid_before_creation',
                    'transaction',
                    $transaction->id,
                    'A transaction cannot be paid before it is created.',
                );
            }
            if ($transaction->paid_at && $transaction->paid_at->gt($dayEnd)) {
                $violations[] = $this->violation(
                    'transaction.paid_at_after_reference',
                    'transaction',
                    $transaction->id,
                    'A completed transaction cannot be dated after the reference date.',
                    ['paid_at' => $transaction->paid_at->toIso8601String()],
                );
            }
        }

        foreach ($context['products'] as $product) {
            $this->appendFutureCreationViolation($violations, 'product', $product->id, $product->created_at, $dayEnd);
        }

        foreach ($context['stockMovements'] as $movement) {
            $this->appendFutureCreationViolation($violations, 'product_stock_movement', $movement->id, $movement->created_at, $dayEnd);
        }

        return $this->check($violations, [
            'reference_day_end_utc' => $dayEnd->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $context
     * @return array<string, mixed>
     */
    private function validateCoverage(User $owner, array $context, CarbonImmutable $reference): array
    {
        $ownerId = (int) ($owner->getKey() ?? 0);
        $timezone = $reference->getTimezone()->getName();
        $coverageStart = $reference->startOfMonth()->subMonths(11);
        $coverageEnd = $reference->endOfDay();
        $monthKeys = collect(range(0, 11))
            ->map(fn (int $offset): string => $coverageStart->addMonths($offset)->format('Y-m'))
            ->all();
        $months = [];

        foreach ($monthKeys as $monthKey) {
            $months[$monthKey] = [
                'customers' => 0,
                'reservations' => 0,
                'invoices' => 0,
                'settled_payments' => 0,
                'stock_movements' => 0,
                'total' => 0,
            ];
        }

        $record = function (mixed $value, string $series) use (&$months, $coverageStart, $coverageEnd, $timezone): void {
            if (! $value) {
                return;
            }

            $date = CarbonImmutable::instance($value)->setTimezone($timezone);
            if ($date->lt($coverageStart) || $date->gt($coverageEnd)) {
                return;
            }

            $monthKey = $date->format('Y-m');
            if (! isset($months[$monthKey])) {
                return;
            }

            $months[$monthKey][$series]++;
            $months[$monthKey]['total']++;
        };

        foreach ($context['customers'] as $customer) {
            $record($customer->created_at, 'customers');
        }

        foreach ($context['reservations']->where('account_id', $ownerId) as $reservation) {
            $record($reservation->starts_at, 'reservations');
        }

        foreach ($context['invoices']->where('user_id', $ownerId) as $invoice) {
            $record($invoice->created_at, 'invoices');
        }

        foreach ($context['payments']->where('user_id', $ownerId)->whereIn('status', Payment::settledStatuses()) as $payment) {
            $record($payment->paid_at, 'settled_payments');
        }

        foreach ($context['stockMovements'] as $movement) {
            $record($movement->created_at, 'stock_movements');
        }

        $missingMonths = collect($months)
            ->filter(fn (array $counts): bool => $counts['total'] === 0)
            ->keys()
            ->values()
            ->all();
        $violations = $missingMonths === []
            ? []
            : [$this->violation(
                'scenario.missing_monthly_coverage',
                'scenario',
                $owner->getKey(),
                'The scenario must contain business activity in each of the last twelve calendar months.',
                ['missing_months' => $missingMonths]
            )];

        return $this->check($violations, [
            'coverage_start' => $coverageStart->toDateString(),
            'coverage_end' => $coverageEnd->toDateString(),
            'expected_month_count' => 12,
            'covered_month_count' => 12 - count($missingMonths),
            'missing_months' => $missingMonths,
            'months' => $months,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function check(array $violations, array $details = []): array
    {
        return [
            'valid' => $violations === [],
            'violation_count' => count($violations),
            'violations' => array_values($violations),
            ...$details,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function violation(
        string $code,
        string $entityType,
        int|string|null $entityId,
        string $message,
        array $context = []
    ): array {
        return [
            'code' => $code,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $violations
     */
    private function appendFutureCreationViolation(
        array &$violations,
        string $entityType,
        int|string|null $entityId,
        mixed $createdAt,
        CarbonImmutable $dayEnd
    ): void {
        if (! $createdAt || ! CarbonImmutable::instance($createdAt)->gt($dayEnd)) {
            return;
        }

        $violations[] = $this->violation(
            "{$entityType}.created_after_reference",
            $entityType,
            $entityId,
            'Scenario records cannot be created after the reference date.',
            ['created_at' => CarbonImmutable::instance($createdAt)->toIso8601String()]
        );
    }
}
