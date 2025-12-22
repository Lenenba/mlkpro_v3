<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;

class WorkBillingService
{
    public function createInvoiceFromWork(Work $work, ?User $actor = null): Invoice
    {
        if ($work->invoice) {
            return $work->invoice;
        }

        $quoteQuery = Quote::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($work) {
                $query->where('work_id', $work->id);
                if ($work->quote_id) {
                    $query->orWhere('id', $work->quote_id);
                }
            });

        $quoteTotal = (float) $quoteQuery->sum('total');
        if ($quoteTotal <= 0) {
            $quoteTotal = (float) ($work->total ?? 0);
        }

        $depositTotal = (float) Transaction::query()
            ->where('work_id', $work->id)
            ->where('type', 'deposit')
            ->where('status', 'completed')
            ->sum('amount');

        $invoiceTotal = max(0, round($quoteTotal - $depositTotal, 2));

        $invoice = Invoice::create([
            'user_id' => $work->user_id,
            'customer_id' => $work->customer_id,
            'work_id' => $work->id,
            'status' => 'sent',
            'total' => $invoiceTotal,
        ]);

        if ($actor) {
            ActivityLog::record($actor, $invoice, 'created', [
                'work_id' => $work->id,
                'total' => $invoice->total,
            ], 'Invoice created from job');
        }

        return $invoice;
    }
}

