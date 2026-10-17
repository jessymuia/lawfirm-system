<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\BackfillInvoiceFx;
use App\Models\Cases;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Payment;
use App\Money\Money;
use App\Reports\PartnerReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** DATA-14: invoices in several currencies, the exchange rate fixed at issue, tax from rules, reports in one currency. */
class MoneyTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matter = Cases::factory()->create();
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);
        $this->actingAs($this->userWithPermissions([P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::MANAGE_FINANCE_SETTINGS, P::VIEW_PARTNER_REPORTS]));
    }

    private function draft(?string $currency = null, string $date = '01/03/2026'): int
    {
        return $this->postJson('/api/v1/invoices', array_filter(['case_id' => $this->matter->id, 'client_id' => $this->matter->client_id,
            'invoice_date' => $date, 'invoice_due_date' => '31/03/2026', 'currency' => $currency]))->assertCreated()->json('data.id');
    }

    private function line(int $invoice, float $rate, array $tax): void
    {
        $this->postJson('/api/v1/invoiceItems', ['invoice_id' => $invoice, 'description' => 'Advice', 'quantity' => 2, 'rate' => $rate] + $tax)->assertOk();
    }

    private function rate(string $currency, float $rate, string $on): void
    {
        $this->postJson('/api/v1/finance/exchange-rates', ['currency' => $currency, 'rate' => $rate, 'effective_on' => $on])->assertOk();
    }

    public function test_a_tax_rate_works_out_the_tax_and_the_line_keeps_its_percentage(): void
    {
        $vat = $this->postJson('/api/v1/finance/tax-rates', ['name' => 'VAT', 'rate' => 16, 'jurisdiction' => 'KE', 'is_default' => true])
            ->assertOk()->json('data.tax_rates.0.id');
        $id = $this->draft();
        $this->line($id, 1000, ['tax_rate_id' => $vat]);
        $this->line($id, 500, ['tax' => 0]);   // a typed amount still works (zero-rated disbursement)

        $invoice = Invoice::find($id);
        $this->assertSame(['3000.00', '320.00', '3320.00'], [$invoice->subtotal, $invoice->tax_total, $invoice->total_amount]);
        $this->assertEquals(16.0, (float) DB::table('invoice_items')->where('tax_rate_id', $vat)->value('tax_rate_percent'));

        // archiving keeps the line's record and stops new lines using it; changing the rate does not touch old lines
        $this->putJson("/api/v1/finance/tax-rates/{$vat}", ['name' => 'VAT', 'rate' => 18])->assertOk();
        $this->assertSame('320.00', Invoice::find($id)->tax_total);
        $this->postJson("/api/v1/finance/tax-rates/{$vat}/archive")->assertOk()->assertJsonPath('data.tax_rates', []);
        $this->postJson('/api/v1/invoiceItems', ['invoice_id' => $id, 'description' => 'x', 'quantity' => 1, 'rate' => 1, 'tax_rate_id' => $vat])
            ->assertUnprocessable()->assertJsonPath('code', 'tax_rate_unavailable');
    }

    public function test_an_invoice_in_another_currency_fixes_its_rate_when_issued(): void
    {
        $this->postJson('/api/v1/invoices', ['case_id' => $this->matter->id, 'client_id' => $this->matter->client_id, 'invoice_date' => '01/03/2026',
            'invoice_due_date' => '31/03/2026', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('currency');

        $id = $this->draft('USD');
        $this->line($id, 100, ['tax' => 0]);
        $this->putJson("/api/v1/send-to-admin/{$id}")->assertUnprocessable()->assertJsonPath('code', 'missing_exchange_rate');
        $this->assertSame('draft', Invoice::find($id)->workflow_status, 'not issued without a rate');

        $this->rate('USD', 129.5, '2026-02-01');
        $this->rate('USD', 131.25, '2026-03-01');
        $this->rate('USD', 140, '2026-03-15');   // after the invoice date: not used
        $this->putJson("/api/v1/send-to-admin/{$id}")->assertOk()
            ->assertJsonPath('data.base_currency', 'KES')->assertJsonPath('data.fx_rate', '131.25000000')->assertJsonPath('data.base_total_amount', '26250.00');
        $this->assertSame('2026-03-01', Invoice::find($id)->fx_rate_on);

        // later rates never change an issued invoice
        $this->rate('USD', 150, '2026-03-01');
        $this->assertSame('26250.00', Invoice::find($id)->base_total_amount);
    }

    public function test_reports_add_up_in_the_base_currency_and_list_what_they_cannot_convert(): void
    {
        $kes = $this->draft();
        $this->line($kes, 5000, ['tax' => 0]);                 // 10,000 KES
        $this->putJson("/api/v1/send-to-admin/{$kes}")->assertOk();
        $usd = $this->draft('USD');
        $this->line($usd, 100, ['tax' => 0]);                  // 200 USD at 130 = 26,000 KES
        $this->rate('USD', 130, '2026-01-01');
        $this->putJson("/api/v1/send-to-admin/{$usd}")->assertOk();
        Payment::create(['invoice_id' => $usd, 'amount' => 50, 'payment_date' => '2026-03-10', 'method' => 'bank_transfer', 'received_by' => auth()->id()]);
        // an old USD invoice issued before rates were fixed: no rate, so it is listed apart, not added at face value
        $legacy = $this->draft('USD');
        $this->line($legacy, 40, ['tax' => 0]);
        DB::table('invoices')->where('id', $legacy)->update(['workflow_status' => 'submitted']);

        $reports = new PartnerReports(auth()->user(), '2026-03-01', '2026-03-31');
        $r = $reports->realisation();
        $this->assertSame(['KES', 36000.0, 6500.0], [$r['currency'], $r['fees_invoiced'], $r['collected']]);
        $this->assertSame([['currency' => 'USD', 'invoices' => 1, 'amount' => 80.0]], $r['unconverted']);

        $ar = $reports->receivables();
        $this->assertSame(10000.0 + 19500.0, $ar['total'], 'outstanding in KES: 10,000 + (200 - 50) USD x 130');
        $usdRow = collect($ar['invoices'])->firstWhere('invoice_id', $usd);
        $this->assertSame(['USD', 150.0, 19500.0], [$usdRow['currency'], $usdRow['outstanding_in_currency'], $usdRow['outstanding']]);
        $this->assertSame([['currency' => 'USD', 'invoices' => 1, 'amount' => 80.0]], $ar['unconverted']);

        $this->assertSame([36000.0, 6500.0], [$reports->profitability()[0]['invoiced'], $reports->profitability()[0]['collected']]);

        // one invoice's amount in the base currency (the dashboard's totals): at its fixed rate, 1 in the base currency,
        // and nothing for an invoice that has no rate
        $this->assertSame([1300.0, 10.0, null], [Invoice::find($usd)->inBase(10), Invoice::find($kes)->forceFill(['fx_rate' => null])->inBase(10), Invoice::find($legacy)->inBase(10)]);
    }

    public function test_invoices_issued_before_rates_were_fixed_are_backfilled_in_the_base_currency_only(): void
    {
        $kes = $this->draft();
        $this->line($kes, 5000, ['tax' => 0]);
        $usd = $this->draft('USD');
        $this->line($usd, 100, ['tax' => 0]);
        DB::table('invoices')->whereIn('id', [$kes, $usd])->update(['workflow_status' => 'submitted']);   // issued the old way

        $this->assertSame(1, (new BackfillInvoiceFx)->handle(app(Money::class)));
        $this->assertSame(['1.00000000', '10000.00'], [Invoice::find($kes)->fx_rate, Invoice::find($kes)->base_total_amount]);
        $this->assertNull(Invoice::find($usd)->fx_rate, 'no rate is invented for history');
        $this->assertSame(0, (new BackfillInvoiceFx)->handle(app(Money::class)));
    }

    public function test_the_base_currency_is_chosen_once_and_settings_are_for_the_right_people(): void
    {
        $this->getJson('/api/v1/finance/settings')->assertOk()->assertJsonPath('data.base_currency', 'KES')
            ->assertJsonPath('data.invoice_currencies', ['KES', 'USD'])->assertJsonPath('data.base_currency_locked', false);
        $this->putJson('/api/v1/finance/base-currency', ['base_currency' => 'ugx'])->assertOk()->assertJsonPath('data.base_currency', 'UGX');
        $this->postJson('/api/v1/finance/exchange-rates', ['currency' => 'UGX', 'rate' => 2, 'effective_on' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonPath('code', 'base_currency_rate');
        $this->putJson('/api/v1/finance/base-currency', ['base_currency' => 'KES'])->assertOk();

        $id = $this->draft();
        $this->line($id, 10, ['tax' => 0]);
        $this->putJson("/api/v1/send-to-admin/{$id}")->assertOk();
        $this->putJson('/api/v1/finance/base-currency', ['base_currency' => 'USD'])->assertStatus(409)->assertJsonPath('code', 'base_currency_locked');

        $this->postJson('/api/v1/finance/currencies', ['code' => 'gbp', 'name' => 'Pound Sterling', 'symbol' => '£'])->assertOk();
        $this->assertSame(['KES', 'GBP', 'USD'], app(Money::class)->currencies());

        $this->actingAs($this->userWithPermissions([P::LIST_INVOICES]));
        $this->getJson('/api/v1/finance/settings')->assertOk();
        $this->postJson('/api/v1/finance/tax-rates', ['name' => 'VAT', 'rate' => 16])->assertForbidden();
    }
}
