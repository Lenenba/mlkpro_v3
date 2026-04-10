<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceDocumentService
{
    public function prepareInvoice(Invoice $invoice): Invoice
    {
        return $invoice->load([
            'customer.properties',
            'items',
            'payments.tipAssignee:id,name',
            'work.products',
            'work.quote.property',
            'work.ratings',
        ]);
    }

    public function filename(Invoice $invoice): string
    {
        $label = $invoice->number ?: $invoice->id;

        return 'invoice-'.$label.'.pdf';
    }

    public function buildPdf(Invoice $invoice, ?User $company = null)
    {
        $invoice = $this->prepareInvoice($invoice);

        return Pdf::loadView('pdf.invoice', $this->buildViewData($invoice, $company))
            ->setOption('isRemoteEnabled', true);
    }

    public function renderPdfContent(Invoice $invoice, ?User $company = null): string
    {
        return $this->buildPdf($invoice, $company)->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(Invoice $invoice, ?User $company = null): array
    {
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
        $totalPaid = round((float) $invoice->payments
            ->whereIn('status', Payment::settledStatuses())
            ->sum('amount'), 2);

        return [
            'company' => $company ?: $this->resolveCompany($invoice),
            'customer' => $invoice->customer,
            'invoice' => $invoice,
            'isTaskBased' => $isTaskBased,
            'productItems' => $productItems,
            'subtotal' => $subtotal,
            'taskItems' => $taskItems,
            'totalPaid' => $totalPaid,
            'work' => $invoice->work,
        ];
    }

    private function resolveCompany(Invoice $invoice): ?User
    {
        if ($invoice->relationLoaded('user')) {
            return $invoice->user;
        }

        return User::find($invoice->user_id);
    }
}
