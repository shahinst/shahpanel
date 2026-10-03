<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Services\InvoiceService;
use App\Support\PersianNumberWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_invoice_pdf_downloads_for_its_owner_only(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $stranger = $this->makeSeller($this->makeAgent());
        [$package, $duration] = $this->makePackage();
        $account = $this->makeAccount($seller, $this->makeServer(), ['package_id' => $package->id, 'package_duration_id' => $duration->id]);
        $invoice = app(InvoiceService::class)->createInvoice($account, InvoiceType::NewAccount, '125000.00');

        $this->actingAs($admin)->get(route('admin.accounting.invoice-pdf', $invoice))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($seller)->get(route('seller.invoices.pdf', $invoice))->assertOk();
        $this->actingAs($stranger)->get(route('seller.invoices.pdf', $invoice))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.accounting.index'))->assertOk()->assertSee('bxs-file-pdf', false);
    }

    public function test_persian_amount_in_words(): void
    {
        $this->assertSame('صد و بیست و پنج هزار', PersianNumberWords::convert(125000));
        $this->assertSame('یک میلیون و دویست و پنجاه هزار', PersianNumberWords::convert(1250000));
        $this->assertSame('هزار و یک', PersianNumberWords::convert(1001));
    }

    public function test_dashboard_ranges_render(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $this->makeAccount($agent, $this->makeServer());

        foreach ([7, 30, 90] as $days) {
            $this->actingAs($admin)->get(route('admin.dashboard', ['range' => $days]))->assertOk()->assertSee('chart-trend', false);
        }
        $this->actingAs($agent)->get(route('agent.dashboard'))->assertOk()->assertSee('chart-money', false);
    }
}
