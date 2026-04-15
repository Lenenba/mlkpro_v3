<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Work;
use App\Services\FinanceApprovalService;
use App\Services\InvoiceDocumentService;
use App\Services\UsageLimitService;
use App\Services\WorkBillingService;
use App\Support\TenantPaymentMethodsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->only([
            'search',
            'status',
            'approval_status',
            'customer_id',
            'total_min',
            'total_max',
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);

        $actor = $request->user();
        $userId = (int) $actor->accountOwnerId();
        $baseQuery = Invoice::query()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'total', 'status', 'approval_status', 'number'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $invoices = (clone $baseQuery)
            ->with([
                'customer',
                'work' => fn ($query) => $query->withAvg('ratings', 'rating')->withCount('ratings'),
            ])
            ->withSum(['payments as payments_sum_amount' => fn ($query) => $query->whereIn('status', Payment::settledStatuses())], 'amount')
            ->orderBy($sort, $direction)
            ->paginate((int) $filters['per_page'])
            ->withQueryString();
        $invoices->setCollection(
            $invoices->getCollection()->map(fn (Invoice $invoice) => $this->presentInvoiceSummary($invoice, $actor))
        );

        $totalCount = (clone $baseQuery)->count();
        $totalValue = (clone $baseQuery)->sum('total');
        $paidCount = (clone $baseQuery)->where('status', 'paid')->count();
        $partialCount = (clone $baseQuery)->where('status', 'partial')->count();
        $openCount = (clone $baseQuery)->whereIn('status', ['draft', 'sent', 'overdue'])->count();
        $outstandingValue = (clone $baseQuery)
            ->whereNotIn('status', ['paid', 'void'])
            ->sum('total');

        $stats = [
            'total' => $totalCount,
            'total_value' => $totalValue,
            'open' => $openCount,
            'paid' => $paidCount,
            'partial' => $partialCount,
            'outstanding' => $outstandingValue,
        ];

        $customers = Customer::byUser($userId)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'first_name', 'last_name']);

        return $this->inertiaOrJson('Invoice/Index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'stats' => $stats,
            'count' => $totalCount,
            'customers' => $customers,
        ]);
    }

    /**
     * Display the specified invoice.
     */
    public function show(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load([
            'customer.properties',
            'items',
            'work.products',
            'work.quote.property',
            'work.ratings',
            'payments.tipAssignee:id,name',
            'creator:id,name',
            'approver:id,name',
            'rejector:id,name',
            'processor:id,name',
        ]);
        $invoice = $this->presentInvoiceDetail($invoice, $request->user());

        $payload = [
            'invoice' => $invoice,
            'paymentMethodSettings' => TenantPaymentMethodsResolver::forAccountId((int) $invoice->user_id),
        ];

        if ($this->shouldReturnJson($request)) {
            $payload['public_url'] = URL::temporarySignedRoute(
                'public.invoices.show',
                now()->addDays(7),
                ['invoice' => $invoice->id]
            );
        }

        return $this->inertiaOrJson('Invoice/Show', $payload);
    }

    public function pdf(Request $request, Invoice $invoice, InvoiceDocumentService $invoiceDocumentService)
    {
        $this->authorize('view', $invoice);

        return $invoiceDocumentService
            ->buildPdf($invoice, $request->user())
            ->download($invoiceDocumentService->filename($invoice));
    }

    /**
     * Create an invoice from a work record.
     */
    public function storeFromWork(Request $request, Work $work, WorkBillingService $billingService)
    {
        $this->authorize('create', Invoice::class);

        if ($work->user_id !== (int) $request->user()->accountOwnerId()) {
            abort(403);
        }

        app(UsageLimitService::class)->enforceLimit($request->user(), 'invoices');

        if ($work->invoice) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'This job already has an invoice.',
                ], 422);
            }

            return redirect()->back()->with('error', 'This job already has an invoice.');
        }

        $invoice = $billingService->createInvoiceFromWork($work, $request->user());

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Invoice created successfully.',
                'invoice' => $invoice->load(['items', 'customer']),
            ], 201);
        }

        return redirect()->route('invoice.show', $invoice)->with('success', 'Invoice created successfully.');
    }

    public function sendEmail(Request $request, Invoice $invoice, WorkBillingService $billingService)
    {
        $this->authorize('send', $invoice);

        $authorization = app(FinanceApprovalService::class)->authorizeInvoiceAction($request->user(), $invoice, 'send');
        if (! ($authorization['allowed'] ?? false)) {
            $message = $authorization['message'] ?? 'You cannot send this invoice.';

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $message,
                    'errors' => [
                        'approval_status' => [$message],
                    ],
                ], 422);
            }

            return redirect()->back()->with('warning', $message);
        }

        $invoice->loadMissing(['customer', 'work']);

        if (! $invoice->customer || ! $invoice->customer->email) {
            $message = 'Customer email address is not available.';

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $message,
                    'errors' => [
                        'customer' => [$message],
                    ],
                ], 422);
            }

            return redirect()->back()->with('warning', $message);
        }

        if ($invoice->status === 'void') {
            $message = 'Void invoices cannot be sent.';

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $message,
                    'errors' => [
                        'status' => [$message],
                    ],
                ], 422);
            }

            return redirect()->back()->with('warning', $message);
        }

        $emailQueued = $billingService->sendInvoiceAvailableNotification($invoice, [
            'source' => 'invoice_manual_send',
        ]);

        if ($emailQueued) {
            ActivityLog::record($request->user(), $invoice, 'email_sent', [
                'email' => $invoice->customer->email,
            ], 'Invoice email sent');
        } else {
            ActivityLog::record($request->user(), $invoice, 'email_failed', [
                'email' => $invoice->customer->email,
            ], 'Invoice email failed');
        }

        if ($emailQueued && $invoice->status === 'draft') {
            $invoice->update(['status' => 'sent']);

            ActivityLog::record($request->user(), $invoice, 'status_changed', [
                'from' => 'draft',
                'to' => 'sent',
            ], 'Invoice status updated');
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $emailQueued
                    ? 'Invoice sent successfully to '.$invoice->customer->email
                    : 'Invoice email could not be sent right now.',
                'warning' => ! $emailQueued,
                'invoice' => $invoice->fresh()->load(['customer', 'work']),
            ], $emailQueued ? 200 : 202);
        }

        if (! $emailQueued) {
            return redirect()->back()->with('warning', 'Invoice email could not be sent right now.');
        }

        return redirect()->back()->with('success', 'Invoice sent successfully to '.$invoice->customer->email);
    }

    public function approve(Request $request, Invoice $invoice)
    {
        return $this->transitionApprovalStatus(
            $request,
            $invoice,
            FinanceApprovalService::APPROVAL_STATUS_APPROVED,
            [
                FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
                FinanceApprovalService::APPROVAL_STATUS_PENDING_APPROVAL,
            ],
            'approve',
            'Invoice approved successfully.',
            [
                'finance_action' => 'approve',
                'clear_approver' => true,
            ]
        );
    }

    public function reject(Request $request, Invoice $invoice)
    {
        return $this->transitionApprovalStatus(
            $request,
            $invoice,
            FinanceApprovalService::APPROVAL_STATUS_REJECTED,
            [
                FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
                FinanceApprovalService::APPROVAL_STATUS_PENDING_APPROVAL,
            ],
            'reject',
            'Invoice rejected successfully.',
            [
                'finance_action' => 'reject',
                'clear_approver' => true,
            ]
        );
    }

    public function markProcessed(Request $request, Invoice $invoice)
    {
        return $this->transitionApprovalStatus(
            $request,
            $invoice,
            FinanceApprovalService::APPROVAL_STATUS_PROCESSED,
            [
                FinanceApprovalService::APPROVAL_STATUS_APPROVED,
            ],
            'process',
            'Invoice marked as processed successfully.',
            [
                'finance_action' => 'process',
                'clear_approver' => true,
            ]
        );
    }

    private function transitionApprovalStatus(
        Request $request,
        Invoice $invoice,
        string $targetStatus,
        array $allowedFrom,
        string $action,
        string $message,
        array $options = []
    ) {
        $this->authorize('transition', $invoice);

        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
        ]);

        if (! in_array((string) $invoice->approval_status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'approval_status' => ['This invoice cannot move to the requested approval state.'],
            ]);
        }

        if (! empty($options['finance_action'])) {
            $authorization = app(FinanceApprovalService::class)->authorizeInvoiceAction(
                $request->user(),
                $invoice,
                (string) $options['finance_action']
            );

            if (! ($authorization['allowed'] ?? false)) {
                throw ValidationException::withMessages([
                    'approval_status' => [$authorization['message'] ?? 'You cannot update this invoice approval state.'],
                ]);
            }
        }

        $fromStatus = (string) $invoice->approval_status;
        $actor = $request->user();
        $actorId = (int) $actor->id;

        $approvalMeta = is_array($invoice->approval_meta) ? $invoice->approval_meta : [];
        $history = $approvalMeta['history'] ?? [];
        if (! is_array($history)) {
            $history = [];
        }
        $history[] = array_filter([
            'action' => $action,
            'from' => $fromStatus,
            'to' => $targetStatus,
            'actor_id' => $actorId,
            'actor_name' => $actor->name,
            'comment' => $validated['comment'] ?? null,
            'created_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');

        $approvalMeta['history'] = array_slice($history, -20);

        $updates = [
            'approval_status' => $targetStatus,
            'approval_meta' => $approvalMeta,
        ];

        if (! empty($options['clear_approver'])) {
            $updates['current_approver_role_key'] = null;
            $updates['current_approval_level'] = null;
        }

        if ($targetStatus === FinanceApprovalService::APPROVAL_STATUS_APPROVED) {
            $updates['approved_by_user_id'] = $actorId;
            $updates['approved_at'] = now();
            $updates['rejected_by_user_id'] = null;
            $updates['rejected_at'] = null;
        }

        if ($targetStatus === FinanceApprovalService::APPROVAL_STATUS_REJECTED) {
            $updates['rejected_by_user_id'] = $actorId;
            $updates['rejected_at'] = now();
        }

        if ($targetStatus === FinanceApprovalService::APPROVAL_STATUS_PROCESSED) {
            $updates['processed_by_user_id'] = $actorId;
            $updates['processed_at'] = now();
        }

        $invoice->fill($updates)->save();
        $invoice->loadMissing([
            'customer',
            'work',
            'creator:id,name',
            'approver:id,name',
            'rejector:id,name',
            'processor:id,name',
        ]);
        $invoice = $this->presentInvoiceDetail($invoice->fresh([
            'customer.properties',
            'items',
            'work.products',
            'work.quote.property',
            'work.ratings',
            'payments.tipAssignee:id,name',
            'creator:id,name',
            'approver:id,name',
            'rejector:id,name',
            'processor:id,name',
        ]), $actor);

        ActivityLog::record($actor, $invoice, 'approval_'.$action, [
            'from' => $fromStatus,
            'to' => $targetStatus,
            'comment' => $validated['comment'] ?? null,
        ], 'Invoice approval workflow updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
                'invoice' => $invoice,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function presentInvoiceSummary(Invoice $invoice, $actor): Invoice
    {
        $invoice->setAttribute('can_send_email', $this->canSendInvoice($actor, $invoice));
        $invoice->setAttribute('available_approval_actions', $this->availableApprovalActions($actor, $invoice));

        return $invoice;
    }

    private function presentInvoiceDetail(Invoice $invoice, $actor): Invoice
    {
        $invoice = $this->presentInvoiceSummary($invoice, $actor);
        $approvalMeta = is_array($invoice->approval_meta) ? $invoice->approval_meta : [];
        $history = $approvalMeta['history'] ?? [];
        $invoice->setAttribute('approval_history', is_array($history) ? array_values($history) : []);

        return $invoice;
    }

    private function canSendInvoice($actor, Invoice $invoice): bool
    {
        if (! $actor) {
            return false;
        }

        if ($invoice->status === 'void') {
            return false;
        }

        if (! $invoice->customer?->email) {
            return false;
        }

        $policy = $actor->can('send', $invoice);
        if (! $policy) {
            return false;
        }

        $authorization = app(FinanceApprovalService::class)->authorizeInvoiceAction($actor, $invoice, 'send');

        return (bool) ($authorization['allowed'] ?? false);
    }

    private function availableApprovalActions($actor, Invoice $invoice): array
    {
        if (! $actor) {
            return [];
        }

        $actions = [];
        if (in_array((string) $invoice->approval_status, [
            FinanceApprovalService::APPROVAL_STATUS_SUBMITTED,
            FinanceApprovalService::APPROVAL_STATUS_PENDING_APPROVAL,
        ], true)) {
            $actions = ['approve', 'reject'];
        } elseif ((string) $invoice->approval_status === FinanceApprovalService::APPROVAL_STATUS_APPROVED) {
            $actions = ['process'];
        }

        return array_values(array_filter($actions, function (string $action) use ($actor, $invoice): bool {
            if (! $actor->can('transition', $invoice)) {
                return false;
            }

            $authorization = app(FinanceApprovalService::class)->authorizeInvoiceAction($actor, $invoice, $action);

            return (bool) ($authorization['allowed'] ?? false);
        }));
    }
}
