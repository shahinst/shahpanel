<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function pdf(Invoice $invoice, InvoiceService $invoiceService): Response
    {
        $this->authorize('view', $invoice);

        return $invoiceService->pdfResponse($invoice);
    }
}
