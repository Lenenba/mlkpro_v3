<?php

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\Invoice;
use App\Models\Customer;
use App\Services\WorkBillingService;
use App\Services\UsageLimitService;
use Barryvdh\DomPDF\Facade\Pdf;
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
            ->withSum('payments', 'amount')
            ->orderBy($sort, $direction)
            ->simplePaginate(10)
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

    public function pdf(Request $request, Invoice $invoice)
    {
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
        }

        $invoice->load([
            'customer.properties',
            'items',
            'work.products',
            'work.ratings',
            'work.quote.property',
            'payments.tipAssignee:id,name',
        ]);

        $isTaskBased = $invoice->items->isNotEmpty();
        $taskItems = collect();
        $productItems = collect();

        if ($isTaskBased) {
            $taskItems = $invoice->items->map(function ($item) {
                return [
                    'title' => $item->title ?: 'Line item',
                    'scheduled_date' => $item->scheduled_date,
                    'start_time' => $item->start_time,
                    'end_time' => $item->end_time,
                    'assignee_name' => $item->assignee_name,
                    'total' => (float) ($item->total ?? 0),
                ];
            });
        } elseif ($invoice->work && $invoice->work->products->isNotEmpty()) {
            $productItems = $invoice->work->products->map(function ($product) {
                $quantity = (float) ($product->pivot?->quantity ?? 0);
                $unitPrice = (float) ($product->pivot?->price ?? $product->price ?? 0);
                $total = (float) ($product->pivot?->total ?? round($quantity * $unitPrice, 2));

                return [
                    'title' => $product->name ?: 'Line item',
                    'description' => $product->pivot?->description ?: $product->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ];
            });
        }

        $subtotal = $isTaskBased
            ? round($taskItems->sum('total'), 2)
            : round($productItems->sum('total'), 2);
        $totalPaid = round((float) $invoice->payments->sum('amount'), 2);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'customer' => $invoice->customer,
            'company' => $request->user(),
            'work' => $invoice->work,
            'isTaskBased' => $isTaskBased,
            'taskItems' => $taskItems,
            'productItems' => $productItems,
            'subtotal' => $subtotal,
            'totalPaid' => $totalPaid,
        ])->setOption('isRemoteEnabled', true);

        $label = $invoice->number ?: $invoice->id;
        $filename = 'invoice-' . $label . '.pdf';

        return $pdf->download($filename);
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
}
