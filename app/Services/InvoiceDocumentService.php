<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceDocumentService
{
    /**
     * @return array<int, array{key:string,label_key:string,description_key:string,view:string}>
     */
    public function templateOptions(): array
    {
        return collect(config('invoices.templates', []))
            ->map(function (array $template, string $key): array {
                return [
                    'key' => $key,
                    'label_key' => (string) ($template['label_key'] ?? ''),
                    'description_key' => (string) ($template['description_key'] ?? ''),
                    'view' => (string) ($template['view'] ?? 'pdf.invoice'),
                ];
            })
            ->values()
            ->all();
    }

    public function defaultTemplateKey(): string
    {
        $configured = (string) config('invoices.default_template', 'modern');
        $templates = config('invoices.templates', []);

        if (is_array($templates) && array_key_exists($configured, $templates)) {
            return $configured;
        }

        return is_array($templates) && array_key_exists('modern', $templates)
            ? 'modern'
            : (array_key_first($templates) ?: 'modern');
    }

    public function templateKeyFor(?User $company = null): string
    {
        $selected = data_get($company?->company_store_settings, 'invoice_template_key');
        $selected = is_string($selected) ? trim($selected) : '';
        $templates = config('invoices.templates', []);

        if ($selected !== '' && is_array($templates) && array_key_exists($selected, $templates)) {
            return $selected;
        }

        return $this->defaultTemplateKey();
    }

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
        $company ??= $this->resolveCompany($invoice);
        $templateKey = $this->templateKeyFor($company);
        $view = (string) data_get(config('invoices.templates', []), $templateKey.'.view', 'pdf.invoice');

        return Pdf::loadView($view, $this->buildViewData($invoice, $company, $templateKey))
            ->setOption('isRemoteEnabled', true);
    }

    public function renderPdfContent(Invoice $invoice, ?User $company = null): string
    {
        return $this->buildPdf($invoice, $company)->output();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(Invoice $invoice, ?User $company = null, ?string $templateKey = null): array
    {
        $hasInvoiceItems = $invoice->items->isNotEmpty();
        $isTaskBased = $hasInvoiceItems && $invoice->items->contains(function ($item): bool {
            return (bool) ($item->task_id || $item->scheduled_date || $item->start_time || $item->end_time || $item->assignee_name);
        });
        $taskItems = collect();
        $productItems = collect();

        if ($isTaskBased) {
            $taskItems = $invoice->items->map(function ($item) {
                return [
                    'title' => $item->title ?: 'Line item',
                    'description' => $item->description,
                    'scheduled_date' => $item->scheduled_date,
                    'start_time' => $item->start_time,
                    'end_time' => $item->end_time,
                    'assignee_name' => $item->assignee_name,
                    'total' => (float) ($item->total ?? 0),
                ];
            });
        } elseif ($hasInvoiceItems) {
            $productItems = $invoice->items->map(function ($item) {
                return [
                    'title' => $item->title ?: 'Line item',
                    'description' => $item->description,
                    'quantity' => (float) ($item->quantity ?? 0),
                    'unit_price' => (float) ($item->unit_price ?? 0),
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
            'invoiceTemplateKey' => $templateKey ?: $this->defaultTemplateKey(),
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
