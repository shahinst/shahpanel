<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\PdfFontSetup;
use Barryvdh\DomPDF\Facade\Pdf;
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

    public function generatePdf(Invoice $invoice): string
    {
        $invoice->loadMissing(['buyer', 'seller', 'agent', 'account.package', 'items']);

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
        ])->setPaper('a4');

        PdfFontSetup::configure($pdf);

        $relativePath = 'invoices/'.$invoice->invoice_number.'.pdf';
        Storage::disk('local')->put($relativePath, $pdf->output());

        return Storage::disk('local')->path($relativePath);
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
