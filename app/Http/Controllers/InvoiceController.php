<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Work;
use App\Services\InvoiceDocumentService;
use App\Services\UsageLimitService;
use App\Services\WorkBillingService;
use App\Support\TenantPaymentMethodsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices.
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'total_min',
            'total_max',
            'created_from',
            'created_to',
            'sort',
            'direction',
        ]);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);

        $userId = Auth::id();
        $baseQuery = Invoice::query()
            ->filter($filters)
            ->byUser($userId);

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'total', 'status', 'number'], true)
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
            ->simplePaginate((int) $filters['per_page'])
            ->withQueryString();

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
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
        }

        $invoice->load([
            'customer.properties',
            'items',
            'work.products',
            'work.quote.property',
            'work.ratings',
            'payments.tipAssignee:id,name',
        ]);

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
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
        }

        return $invoiceDocumentService
            ->buildPdf($invoice, $request->user())
            ->download($invoiceDocumentService->filename($invoice));
    }

    /**
     * Create an invoice from a work record.
     */
    public function storeFromWork(Request $request, Work $work, WorkBillingService $billingService)
    {
        if ($work->user_id !== Auth::id()) {
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
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
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
}
