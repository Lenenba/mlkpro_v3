<?php

namespace App\Http\Controllers;

use App\Models\Work;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            ->with(['customer', 'work'])
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

        return inertia('Invoice/Index', [
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
    public function show(Invoice $invoice)
    {
        if ($invoice->user_id !== Auth::id()) {
            abort(403);
        }

        return inertia('Invoice/Show', [
            'invoice' => $invoice->load(['customer', 'work', 'payments']),
        ]);
    }

    /**
     * Create an invoice from a work record.
     */
    public function storeFromWork(Request $request, Work $work)
    {
        if ($work->user_id !== Auth::id()) {
            abort(403);
        }

        if ($work->invoice) {
            return redirect()->back()->with('error', 'This job already has an invoice.');
        }

        $invoice = DB::transaction(function () use ($work) {
            return Invoice::create([
                'user_id' => $work->user_id,
                'customer_id' => $work->customer_id,
                'work_id' => $work->id,
                'status' => 'draft',
                'total' => $work->total ?? 0,
            ]);
        });

        ActivityLog::record($request->user(), $invoice, 'created', [
            'work_id' => $work->id,
            'total' => $invoice->total,
        ], 'Invoice created from job');

        return redirect()->route('invoice.show', $invoice)->with('success', 'Invoice created successfully.');
    }
}
