<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ActionEmailNotification;
use App\Services\UsageLimitService;
use Illuminate\Support\Facades\URL;
use App\Services\TemplateService;

class WorkBillingService
{
    public function createInvoiceFromWork(Work $work, ?User $actor = null): Invoice
    {
        if ($work->invoice) {
            return $work->invoice;
        }

        $limitUser = $actor ?: User::find($work->user_id);
        if ($limitUser) {
            app(UsageLimitService::class)->enforceLimit($limitUser, 'invoices');
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

        $customer = $work->customer;
        if ($customer && $customer->email) {
            $accountOwner = User::find($work->user_id);
            $note = $accountOwner
                ? app(TemplateService::class)->resolveInvoiceNote($accountOwner)
                : null;
            $usePublicLink = !(bool) ($customer->portal_access ?? true) || !$customer->portal_user_id;
            $actionUrl = route('dashboard');
            $actionLabel = 'Open dashboard';
            if ($usePublicLink) {
                $expiresAt = now()->addDays(7);
                $actionUrl = URL::temporarySignedRoute(
                    'public.invoices.show',
                    $expiresAt,
                    ['invoice' => $invoice->id]
                );
                $actionLabel = 'Pay invoice';
            }
            $customer->notify(new ActionEmailNotification(
                'New invoice available',
                'A new invoice has been generated for your job.',
                [
                    ['label' => 'Invoice', 'value' => $invoice->number ?? $invoice->id],
                    ['label' => 'Job', 'value' => $work->job_title ?? $work->number ?? $work->id],
                    ['label' => 'Total', 'value' => '$' . number_format((float) $invoice->total, 2)],
                ],
                $actionUrl,
                $actionLabel,
                'New invoice available',
                $note
            ));
        }

        return $invoice;
    }
}
