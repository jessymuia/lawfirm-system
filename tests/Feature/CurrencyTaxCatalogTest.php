<?php

namespace Tests\Feature;

use App\Catalog\SystemCatalog;
use App\Constants\ModulePermissions as P;
use App\Models\Currency;
use App\Models\TaxRate;
use App\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** TEN-11 + DATA-14: currencies and tax rates are platform reference data; what a firm uses of them stays its choice. */
class CurrencyTaxCatalogTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_every_firm_gets_the_currencies_and_kenyan_vat_with_vat_as_its_default(): void
    {
        $report = SystemCatalog::sync();
        $this->assertSame(count(SystemCatalog::CURRENCIES), $report['currencies']['created']);
        $this->assertSame(count(SystemCatalog::TAX_RATES), $report['tax_rates']['created']);

        $this->assertSame(['UGX' => 0, 'USD' => 2], Currency::whereIn('code', ['UGX', 'USD'])->pluck('minor_units', 'code')->all());
        $this->assertSame(0, Currency::where('enabled', true)->count(), 'off until the firm invoices in them');
        $this->assertSame(['KES'], app(Money::class)->currencies(), 'the base currency is always available');
        $vat = TaxRate::where('catalog_key', 'ke_vat_standard')->firstOrFail();
        $this->assertSame(['VAT', '16.0000', 'KE', true, true], [$vat->name, $vat->rate, $vat->jurisdiction, $vat->is_default, $vat->is_system]);
        $this->assertSame(1, TaxRate::where('is_default', true)->count());

        $again = SystemCatalog::sync();
        foreach (['currencies', 'tax_rates'] as $t) {
            $this->assertSame(0, $again[$t]['created'] + $again[$t]['adopted'] + $again[$t]['updated'], "{$t} changed on a second run");
        }
    }

    public function test_the_firms_choices_survive_every_sync_and_a_change_in_the_law_reaches_every_firm(): void
    {
        // a firm that already had its own VAT 16% row: adopted, so its invoice lines keep pointing at it
        $own = TaxRate::create(['name' => 'vat', 'rate' => 16, 'jurisdiction' => 'KE', 'is_default' => false]);
        $zero = TaxRate::create(['name' => 'Disbursements', 'rate' => 0, 'is_default' => true]);
        $this->assertSame(1, SystemCatalog::sync()['tax_rates']['adopted']);
        $this->assertSame('ke_vat_standard', $own->fresh()->catalog_key);
        $this->assertTrue($zero->fresh()->is_default, 'the firm already had a default: the catalog does not take it over');

        Currency::where('code', 'USD')->update(['enabled' => true]);
        TaxRate::where('catalog_key', 'ke_vat_exempt')->update(['archived_at' => now()]);
        SystemCatalog::sync();
        $this->assertTrue(Currency::where('code', 'USD')->value('enabled'));
        $this->assertNotNull(TaxRate::where('catalog_key', 'ke_vat_exempt')->value('archived_at'), 'archiving is the firm\'s choice');

        // tampering is repaired; old lines keep the percentage they were charged at
        DB::table('tax_rates')->where('catalog_key', 'ke_vat_standard')->update(['rate' => 18]);
        $this->assertSame(1, SystemCatalog::sync()['tax_rates']['updated']);
        $this->assertSame('16.0000', TaxRate::where('catalog_key', 'ke_vat_standard')->value('rate'));
    }

    public function test_a_firm_cannot_rename_a_system_rate_or_currency_but_can_use_them(): void
    {
        SystemCatalog::sync();
        $vat = TaxRate::where('catalog_key', 'ke_vat_standard')->value('id');
        $this->actingAs($this->userWithPermissions([P::LIST_INVOICES, P::MANAGE_FINANCE_SETTINGS]));

        $this->putJson("/api/v1/finance/tax-rates/{$vat}", ['name' => 'VAT', 'rate' => 14])->assertStatus(409)->assertJsonPath('code', 'system_catalog');
        $this->putJson("/api/v1/finance/tax-rates/{$vat}", ['name' => 'VAT', 'rate' => 16, 'jurisdiction' => 'KE', 'is_default' => true])->assertOk();
        $this->postJson("/api/v1/finance/tax-rates/{$vat}/archive")->assertOk();

        $this->postJson('/api/v1/finance/currencies', ['code' => 'USD', 'name' => 'Greenback', 'enabled' => true])->assertOk()
            ->assertJsonPath('data.invoice_currencies', ['KES', 'USD']);
        $this->assertSame('US Dollar', Currency::where('code', 'USD')->value('name'));
    }
}
