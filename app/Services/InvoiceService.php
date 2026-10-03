<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoiceService
{
    public function createInvoice(Account $account, InvoiceType $type, string $amount): Invoice
    {
        $account->loadMissing(['ownerSeller', 'ownerAgent', 'package']);

        $currency = $account->package?->moneyCurrency()->value
            ?? \App\Enums\MoneyCurrency::default()->value;

        $payload = [
            'invoice_number' => $this->generateInvoiceNumber(),
            'buyer_user_id' => $account->owner_seller_id,
            'seller_user_id' => $account->owner_seller_id,
            'agent_user_id' => $account->owner_agent_id,
            'account_id' => $account->id,
            'type' => $type,
            'subtotal' => $amount,
            'total' => $amount,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
            'created_at' => now(),
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'currency')) {
            $payload['currency'] = $currency;
        }

        $invoice = Invoice::query()->create($payload);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'description' => $this->buildItemDescription($account, $type),
            'quantity' => 1,
            'unit_price' => $amount,
            'total' => $amount,
            'package_id' => $account->package_id,
        ]);

        return $invoice->fresh(['items', 'account.package']);
    }

    /**
     * The invoice as PDF bytes: Persian text shaped by mPDF, Vazirmatn,
     * Persian digits and Jalali dates on Persian pages.
     */
    public function renderPdf(Invoice $invoice): string
    {
        $invoice->loadMissing(['buyer', 'seller', 'agent', 'account.package', 'account.packageDuration', 'account.server', 'account.clientUser', 'items']);

        $amount = (float) $invoice->total;

        return \App\Support\PersianPdf::render('pdf.invoice', [
            'pdfTitle' => __('ui.sales_invoice').' '.$invoice->invoice_number,
            'invoice' => $invoice,
            'client' => $invoice->account?->clientUser,
            // Only what the buyer paid: the other legs (agent margin, admin
            // revenue) are the panel's business, not the customer's.
            'payments' => \App\Models\Transaction::query()
                ->where('related_invoice_id', $invoice->id)
                ->where('user_id', $invoice->buyer_user_id)
                ->whereIn('type', [
                    \App\Enums\TransactionType::Purchase,
                    \App\Enums\TransactionType::Renewal,
                    \App\Enums\TransactionType::ClientCost,
                ])
                ->orderBy('id')
                ->get(),
            'amountInWords' => locale_digits() === 'fa' && $amount > 0
                ? \App\Support\PersianNumberWords::convert($amount).' '.\App\Enums\MoneyCurrency::normalize($invoice->currency)->label()
                : null,
            'panel' => [
                'name' => (string) (\App\Models\Setting::getValue('site_name') ?: config('app.name')),
                'url' => (string) (\App\Models\Setting::getValue('site_url') ?: ''),
                'phone' => (string) (\App\Models\Setting::getValue('support_phone') ?: ''),
                'telegram' => (string) (\App\Models\Setting::getValue('support_telegram') ?: ''),
            ],
        ]);
    }

    /**
     * @deprecated kept for callers that expect a file path; renderPdf() is
     * what the download routes use.
     */
    public function generatePdf(Invoice $invoice): string
    {
        $relativePath = 'invoices/'.$invoice->invoice_number.'.pdf';
        Storage::disk('local')->put($relativePath, $this->renderPdf($invoice));

        return Storage::disk('local')->path($relativePath);
    }

    public function pdfResponse(Invoice $invoice): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->renderPdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function generateInvoiceNumber(): string
    {
        do {
            $number = 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Invoice::query()->where('invoice_number', $number)->exists());

        return $number;
    }

    protected function buildItemDescription(Account $account, InvoiceType $type): string
    {
        $packageName = $account->package?->name ?? 'VPN Package';

        if ($account->package?->isElastic() && $account->purchased_data_gb !== null) {
            $gb = rtrim(rtrim(number_format((float) $account->purchased_data_gb, 2, '.', ''), '0'), '.');
            $packageName .= " ({$gb} GB)";
        }

        return match ($type) {
            InvoiceType::NewAccount => "ایجاد اکانت — {$packageName}",
            InvoiceType::Renewal => "تمدید اکانت — {$packageName}",
            InvoiceType::Adjustment => "تعدیل — {$packageName}",
        };
    }
}
