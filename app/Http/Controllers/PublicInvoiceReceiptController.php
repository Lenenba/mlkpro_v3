<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use Illuminate\Http\Response;

class PublicInvoiceReceiptController extends Controller
{
    public function show(Invoice $invoice, InvoiceDocumentService $invoiceDocumentService): Response
    {
        abort_unless((string) $invoice->status === 'paid', 404);

        $filename = 'receipt-'.($invoice->number ?: $invoice->id).'.pdf';

        return $invoiceDocumentService
            ->buildPdf($invoice)
            ->download($filename);
    }
}
