<?php

namespace App\Http\Controllers\Agent;

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
            ->ownedByHierarchy($request->user())
            ->with(['buyer', 'seller', 'account'])
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('agent.invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['buyer', 'seller', 'account', 'items']);

        return view('agent.invoices.show', compact('invoice'));
    }

    public function pdf(Invoice $invoice, \App\Services\InvoiceService $invoiceService): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorize('view', $invoice);

        return response()->download($invoiceService->generatePdf($invoice));
    }
}
