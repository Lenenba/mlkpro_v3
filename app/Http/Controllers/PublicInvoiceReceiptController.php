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

        $response = $invoiceDocumentService
            ->buildPdf($invoice)
            ->download($filename);

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
