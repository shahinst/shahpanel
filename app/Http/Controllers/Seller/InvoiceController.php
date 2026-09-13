<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->where('seller_user_id', $request->user()->id)
            ->with(['buyer', 'account'])
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('seller.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['buyer', 'account', 'items']);

        return view('seller.invoices.show', compact('invoice'));
    }

    public function pdf(Invoice $invoice, \App\Services\InvoiceService $invoiceService): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorize('view', $invoice);

        return response()->download($invoiceService->generatePdf($invoice));
    }
}
