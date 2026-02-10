<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentTipAllocation;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TipReportController extends Controller
{
    private ?bool $supportsAllocations = null;

    public function ownerIndex(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        $membership = $this->resolveMembership($accountId, $user->id);
        $this->ensureOwnerTipsAccess($user, $accountId, $membership);

        $filters = $this->validatedOwnerFilters($request, $accountId);
        $query = $this->buildOwnerTipsQuery($accountId, $filters);

        $payments = (clone $query)
            ->with($this->tipRelations())
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn(Payment $payment) => $this->serializePayment($payment));

        $statsQuery = clone $query;
        $totalTips = $this->sumNetTips($statsQuery);
        $reservationCount = (int) (clone $statsQuery)
            ->whereNotNull('invoice_id')
            ->distinct('invoice_id')
            ->count('invoice_id');
        $averageTip = $reservationCount > 0
            ? round($totalTips / $reservationCount, 2)
            : 0.0;

        $topMembers = $this->topMembersForOwner($query);

        return $this->inertiaOrJson('Tips/OwnerIndex', [
            'filters' => $filters,
            'payments' => $payments,
            'canViewInvoice' => $user->id === $accountId,
            'stats' => [
                'total_tips' => $totalTips,
                'average_tip_per_reservation' => $averageTip,
                'reservation_count' => $reservationCount,
                'top_members' => $topMembers,
            ],
            'teamMembers' => $this->tipTeamMemberOptions($accountId),
            'works' => $this->tipWorkOptions($accountId),
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function ownerExport(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        $membership = $this->resolveMembership($accountId, $user->id);
        $this->ensureOwnerTipsAccess($user, $accountId, $membership);

        $filters = $this->validatedOwnerFilters($request, $accountId);

        $rows = $this->buildOwnerTipsQuery($accountId, $filters)
            ->with($this->tipRelations())
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $filename = 'tips-report-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'date_time',
                'invoice',
                'customer',
                'team_member',
                'tip_mode',
                'tip_amount',
                'charged_total',
                'payment_status',
            ]);

            foreach ($rows as $payment) {
                $serialized = $this->serializePayment($payment);
                fputcsv($handle, [
                    $serialized['paid_at'] ?: '',
                    $serialized['invoice_number'] ?: '',
                    $serialized['customer_name'] ?: '',
                    $serialized['team_member_name'] ?: '',
                    $serialized['tip_mode'] ?: '',
                    number_format((float) ($serialized['tip_amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($serialized['charged_total'] ?? 0), 2, '.', ''),
                    $serialized['status'] ?: '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function memberIndex(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $accountId = $user->accountOwnerId();
        $membership = $this->resolveMembership($accountId, $user->id);
        if (!$membership) {
            abort(403);
        }

        $filters = $this->validatedMemberFilters($request);
        $query = $this->buildMemberTipsQuery($accountId, $user->id, $filters);

        $payments = (clone $query)
            ->with($this->tipRelations($user->id))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn(Payment $payment) => $this->serializePaymentForMember(
                $payment,
                $user->id,
                (bool) ($filters['anonymize_customers'] ?? false)
            ));

        $periodTotal = $this->sumNetTips(clone $query, $user->id);
        $serviceCount = (int) (clone $query)
            ->whereNotNull('invoice_id')
            ->distinct('invoice_id')
            ->count('invoice_id');
        $averageTipPerService = $serviceCount > 0
            ? round($periodTotal / $serviceCount, 2)
            : 0.0;

        $currentMonthTotal = $this->sumNetTips($this->buildMemberTipsQuery($accountId, $user->id, [
            'period' => 'month',
            'status' => null,
            'anonymize_customers' => $filters['anonymize_customers'] ?? false,
        ]), $user->id);

        return $this->inertiaOrJson('Tips/MemberIndex', [
            'filters' => $filters,
            'payments' => $payments,
            'stats' => [
                'current_month_total' => $currentMonthTotal,
                'period_total' => $periodTotal,
                'average_tip_per_service' => $averageTipPerService,
                'service_count' => $serviceCount,
            ],
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    private function validatedOwnerFilters(Request $request, int $accountId): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'month', 'custom'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'team_member_id' => ['nullable', Rule::exists('users', 'id')],
            'work_id' => [
                'nullable',
                Rule::exists('works', 'id')->where(fn($query) => $query->where('user_id', $accountId)),
            ],
            'status' => ['nullable', Rule::in($this->statusOptions())],
        ]);

        return [
            'period' => $validated['period'] ?? '30d',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'team_member_id' => isset($validated['team_member_id']) ? (int) $validated['team_member_id'] : null,
            'work_id' => isset($validated['work_id']) ? (int) $validated['work_id'] : null,
            'status' => $validated['status'] ?? null,
        ];
    }

    private function validatedMemberFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7d', '30d', '90d', 'month', 'custom'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in($this->statusOptions())],
            'anonymize_customers' => ['nullable', 'boolean'],
        ]);

        return [
            'period' => $validated['period'] ?? '30d',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'status' => $validated['status'] ?? null,
            'anonymize_customers' => (bool) ($validated['anonymize_customers'] ?? false),
        ];
    }

    private function buildOwnerTipsQuery(int $accountId, array $filters): Builder
    {
        $query = $this->baseTipsQuery($accountId);

        if (!empty($filters['team_member_id'])) {
            if ($this->supportsAllocations()) {
                $teamMemberId = (int) $filters['team_member_id'];
                $query->whereHas('tipAllocations', function (Builder $allocationQuery) use ($teamMemberId) {
                    $allocationQuery->where('user_id', $teamMemberId);
                });
            } else {
                $query->where('tip_assignee_user_id', $filters['team_member_id']);
            }
        }

        if (!empty($filters['work_id'])) {
            $workId = (int) $filters['work_id'];
            $query->whereHas('invoice', fn(Builder $invoiceQuery) => $invoiceQuery->where('work_id', $workId));
        }

        return $this->applyCommonTipFilters($query, $filters);
    }

    private function buildMemberTipsQuery(int $accountId, int $memberUserId, array $filters): Builder
    {
        $query = $this->baseTipsQuery($accountId);

        if ($this->supportsAllocations()) {
            $query->whereHas('tipAllocations', function (Builder $allocationQuery) use ($memberUserId) {
                $allocationQuery->where('user_id', $memberUserId);
            });
        } else {
            $query->where('tip_assignee_user_id', $memberUserId);
        }

        return $this->applyCommonTipFilters($query, $filters);
    }

    private function applyCommonTipFilters(Builder $query, array $filters): Builder
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $this->applyPeriodFilter(
            $query,
            (string) ($filters['period'] ?? '30d'),
            $filters['from'] ?? null,
            $filters['to'] ?? null
        );

        return $query;
    }

    private function applyPeriodFilter(Builder $query, string $period, ?string $from, ?string $to): void
    {
        $period = in_array($period, ['7d', '30d', '90d', 'month', 'custom'], true) ? $period : '30d';
        $now = now();

        if ($period === 'custom') {
            if ($from) {
                $query->whereDate('paid_at', '>=', $from);
            }
            if ($to) {
                $query->whereDate('paid_at', '<=', $to);
            }
            return;
        }

        [$start, $end] = match ($period) {
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '90d' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        $query->whereBetween('paid_at', [$start, $end]);
    }

    private function tipRelations(?int $memberUserId = null): array
    {
        $relations = [
            'invoice:id,number,work_id,customer_id',
            'invoice.work:id,job_title',
            'customer:id,company_name,first_name,last_name',
            'tipAssignee:id,name',
        ];

        if ($this->supportsAllocations()) {
            $relations['tipAllocations'] = function ($query) use ($memberUserId) {
                if ($memberUserId) {
                    $query->where('user_id', $memberUserId);
                }

                $query->with('user:id,name');
            };
        }

        return $relations;
    }

    private function baseTipsQuery(int $accountId): Builder
    {
        return Payment::query()
            ->where('user_id', $accountId)
            ->whereNull('sale_id')
            ->where('tip_amount', '>', 0);
    }

    private function serializePayment(Payment $payment, bool $anonymizeCustomer = false): array
    {
        $tipAmount = $this->paymentNetTipAmount($payment);
        $chargedTotal = $payment->charged_total !== null
            ? (float) $payment->charged_total
            : round((float) ($payment->amount ?? 0) + (float) ($payment->tip_amount ?? 0), 2);

        $customerName = $this->customerLabel($payment);
        if ($anonymizeCustomer) {
            $customerName = 'Customer #' . (int) ($payment->customer_id ?? 0);
        }

        return [
            'id' => $payment->id,
            'paid_at' => $payment->paid_at?->toDateTimeString(),
            'invoice_id' => $payment->invoice_id,
            'invoice_number' => $payment->invoice?->number ?? ('#' . (int) ($payment->invoice_id ?? 0)),
            'customer_name' => $customerName,
            'work_id' => $payment->invoice?->work_id,
            'work_title' => $payment->invoice?->work?->job_title,
            'team_member_name' => $this->teamMemberLabelForOwner($payment),
            'tip_mode' => $payment->tip_type ?: 'fixed',
            'tip_amount' => $tipAmount,
            'amount' => (float) ($payment->amount ?? 0),
            'charged_total' => $chargedTotal,
            'status' => $this->tipStatus($payment),
        ];
    }

    private function serializePaymentForMember(Payment $payment, int $memberUserId, bool $anonymizeCustomer = false): array
    {
        $serialized = $this->serializePayment($payment, $anonymizeCustomer);
        $serialized['tip_amount'] = $this->memberNetTipAmount($payment, $memberUserId);
        $serialized['team_member_name'] = null;

        if ($this->supportsAllocations()) {
            $allocation = $payment->tipAllocations
                ?->first(fn($item) => (int) ($item->user_id ?? 0) === $memberUserId);
            if ($allocation && $serialized['tip_amount'] <= 0 && (float) ($allocation->reversed_amount ?? 0) > 0) {
                $serialized['status'] = 'reversed';
            }
        }

        return $serialized;
    }

    private function customerLabel(Payment $payment): string
    {
        $customer = $payment->customer;
        if (!$customer) {
            return 'Customer';
        }

        $company = trim((string) ($customer->company_name ?? ''));
        if ($company !== '') {
            return $company;
        }

        $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        return $fullName !== '' ? $fullName : 'Customer';
    }

    private function tipTeamMemberOptions(int $accountId): array
    {
        return TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->with('user:id,name')
            ->get()
            ->map(function (TeamMember $member) {
                return [
                    'id' => (int) $member->user_id,
                    'name' => $member->user?->name ?: 'Team member',
                ];
            })
            ->filter(fn($item) => !empty($item['id']))
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function tipWorkOptions(int $accountId): array
    {
        return Work::query()
            ->where('user_id', $accountId)
            ->whereHas('invoice.payments', fn(Builder $paymentQuery) => $paymentQuery->where('tip_amount', '>', 0))
            ->orderBy('job_title')
            ->get(['id', 'job_title'])
            ->map(function (Work $work) {
                return [
                    'id' => $work->id,
                    'title' => $work->job_title ?: ('Job #' . $work->id),
                ];
            })
            ->values()
            ->all();
    }

    private function statusOptions(): array
    {
        return ['pending', 'completed', 'reversed', 'failed', 'refunded'];
    }

    private function resolveMembership(int $accountId, int $userId): ?TeamMember
    {
        return TeamMember::query()
            ->forAccount($accountId)
            ->active()
            ->where('user_id', $userId)
            ->first();
    }

    private function ensureOwnerTipsAccess(User $user, int $accountId, ?TeamMember $membership): void
    {
        $isOwner = $user->id === $accountId && $user->isOwner();
        $isTeamAdmin = $membership && $membership->role === 'admin';

        if (!$isOwner && !$isTeamAdmin) {
            abort(403);
        }
    }

    private function supportsAllocations(): bool
    {
        if ($this->supportsAllocations !== null) {
            return $this->supportsAllocations;
        }

        $this->supportsAllocations = Schema::hasTable('payment_tip_allocations');
        return $this->supportsAllocations;
    }

    private function paymentNetTipAmount(Payment $payment): float
    {
        $tip = (float) ($payment->tip_amount ?? 0);
        $reversed = (float) ($payment->tip_reversed_amount ?? 0);
        return round(max(0, $tip - $reversed), 2);
    }

    private function memberNetTipAmount(Payment $payment, int $memberUserId): float
    {
        if (!$this->supportsAllocations()) {
            if ((int) ($payment->tip_assignee_user_id ?? 0) !== $memberUserId) {
                return 0.0;
            }

            return $this->paymentNetTipAmount($payment);
        }

        $allocation = $payment->tipAllocations
            ?->first(fn($item) => (int) ($item->user_id ?? 0) === $memberUserId);
        if (!$allocation) {
            return 0.0;
        }

        $amount = (float) ($allocation->amount ?? 0);
        $reversed = (float) ($allocation->reversed_amount ?? 0);
        return round(max(0, $amount - $reversed), 2);
    }

    private function teamMemberLabelForOwner(Payment $payment): ?string
    {
        if ($this->supportsAllocations() && $payment->relationLoaded('tipAllocations')) {
            $names = $payment->tipAllocations
                ->filter(function ($allocation) {
                    $net = (float) ($allocation->amount ?? 0) - (float) ($allocation->reversed_amount ?? 0);
                    return $net > 0;
                })
                ->sortByDesc(fn($allocation) => (float) ($allocation->amount ?? 0))
                ->map(fn($allocation) => $allocation->user?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (!empty($names)) {
                return implode(', ', $names);
            }
        }

        return $payment->tipAssignee?->name;
    }

    private function tipStatus(Payment $payment): string
    {
        $tipAmount = (float) ($payment->tip_amount ?? 0);
        $reversed = (float) ($payment->tip_reversed_amount ?? 0);
        if ($tipAmount > 0 && $reversed >= $tipAmount) {
            return 'refunded';
        }
        if ($reversed > 0 && in_array($payment->status, ['completed', 'pending'], true)) {
            return 'reversed';
        }

        return (string) ($payment->status ?: 'completed');
    }

    private function sumNetTips(Builder $query, ?int $memberUserId = null): float
    {
        if ($this->supportsAllocations() && $memberUserId) {
            $paymentIds = (clone $query)->select('payments.id');
            $sum = PaymentTipAllocation::query()
                ->joinSub($paymentIds, 'filtered_payments', function ($join) {
                    $join->on('filtered_payments.id', '=', 'payment_tip_allocations.payment_id');
                })
                ->where('payment_tip_allocations.user_id', $memberUserId)
                ->selectRaw('SUM(' . $this->allocationNetExpression() . ') as total')
                ->value('total');

            return round((float) ($sum ?? 0), 2);
        }

        $sum = (clone $query)
            ->selectRaw('SUM(' . $this->paymentNetExpression() . ') as total')
            ->value('total');

        return round((float) ($sum ?? 0), 2);
    }

    private function topMembersForOwner(Builder $query): array
    {
        if ($this->supportsAllocations()) {
            $paymentIds = (clone $query)->select('payments.id');
            $rows = PaymentTipAllocation::query()
                ->joinSub($paymentIds, 'filtered_payments', function ($join) {
                    $join->on('filtered_payments.id', '=', 'payment_tip_allocations.payment_id');
                })
                ->selectRaw('payment_tip_allocations.user_id, SUM(' . $this->allocationNetExpression() . ') as total_tips, COUNT(DISTINCT payment_tip_allocations.payment_id) as tips_count')
                ->groupBy('payment_tip_allocations.user_id')
                ->orderByDesc('total_tips')
                ->limit(3)
                ->get();
        } else {
            $rows = (clone $query)
                ->whereNotNull('tip_assignee_user_id')
                ->selectRaw('tip_assignee_user_id as user_id, SUM(' . $this->paymentNetExpression() . ') as total_tips, COUNT(*) as tips_count')
                ->groupBy('tip_assignee_user_id')
                ->orderByDesc('total_tips')
                ->limit(3)
                ->get();
        }

        $topUserMap = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->all())
            ->pluck('name', 'id');

        return $rows->map(function ($row) use ($topUserMap) {
            $userId = (int) ($row->user_id ?? 0);

            return [
                'user_id' => $userId,
                'name' => $topUserMap[$userId] ?? 'Team member',
                'total_tips' => round((float) ($row->total_tips ?? 0), 2),
                'tips_count' => (int) ($row->tips_count ?? 0),
            ];
        })->values()->all();
    }

    private function paymentNetExpression(): string
    {
        return 'CASE WHEN tip_amount - COALESCE(tip_reversed_amount, 0) > 0 THEN tip_amount - COALESCE(tip_reversed_amount, 0) ELSE 0 END';
    }

    private function allocationNetExpression(): string
    {
        return 'CASE WHEN payment_tip_allocations.amount - COALESCE(payment_tip_allocations.reversed_amount, 0) > 0 THEN payment_tip_allocations.amount - COALESCE(payment_tip_allocations.reversed_amount, 0) ELSE 0 END';
    }
}
