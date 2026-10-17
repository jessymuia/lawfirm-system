<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\BaseCurrencyRequest;
use App\Http\Requests\Finance\CurrencyRequest;
use App\Http\Requests\Finance\ExchangeRateRequest;
use App\Http\Requests\Finance\NumberingRequest;
use App\Http\Requests\Finance\TaxRateRequest;
use App\Models\ExchangeRate;
use App\Models\TaxRate;
use App\Money\FinanceSettings;
use App\Security\SecurityPolicy;
use Illuminate\Support\Facades\Auth;

/** DATA-14: Settings → Currencies and tax (docs/MONEY.md). */
class FinanceSettingsController extends Controller
{
    public function __construct(private FinanceSettings $settings) {}

    public function show()
    {
        return $this->response(true, 'success', $this->settings->overview(), 200);
    }

    public function baseCurrency(BaseCurrencyRequest $r)
    {
        $this->settings->setBaseCurrency($r->validated('base_currency'), Auth::id());
        SecurityPolicy::audit('base_currency_changed', 'firm', 0, [], ['base_currency' => strtoupper($r->validated('base_currency'))]);

        return $this->show();
    }

    public function numbering(NumberingRequest $r)
    {
        $before = $this->settings->numbering();
        $after = $this->settings->saveNumbering($r->validated() + ['prefixes' => []], Auth::id());
        SecurityPolicy::audit('numbering_changed', 'firm', 0, ['per_year' => $before['per_year'], 'prefixes' => $before['prefixes']], ['per_year' => $after['per_year'], 'prefixes' => $after['prefixes']]);

        return $this->show();
    }

    public function currency(CurrencyRequest $r)
    {
        $c = $this->settings->saveCurrency($r->validated());
        SecurityPolicy::audit('currency_saved', 'currency', $c->id, [], $c->only(['code', 'enabled']));

        return $this->show();
    }

    public function exchangeRate(ExchangeRateRequest $r)
    {
        $rate = $this->settings->saveRate($r->validated(), Auth::id());
        SecurityPolicy::audit('exchange_rate_saved', 'exchange_rate', $rate->id, [], ['currency' => $rate->currency, 'rate' => (string) $rate->rate, 'effective_on' => $rate->effective_on->toDateString()]);

        return $this->show();
    }

    public function deleteExchangeRate(int $id)
    {
        $rate = ExchangeRate::findOrFail($id);
        $rate->delete();   // issued invoices keep the rate they fixed
        SecurityPolicy::audit('exchange_rate_deleted', 'exchange_rate', $id, ['currency' => $rate->currency, 'effective_on' => $rate->effective_on->toDateString()], []);

        return $this->show();
    }

    public function taxRate(TaxRateRequest $r, ?int $id = null)
    {
        $rate = $this->settings->saveTaxRate($r->validated(), $id ? TaxRate::whereNull('archived_at')->findOrFail($id) : null, Auth::id());
        SecurityPolicy::audit('tax_rate_saved', 'tax_rate', $rate->id, [], $rate->only(['name', 'rate', 'is_default']));

        return $this->show();
    }

    public function archiveTaxRate(int $id)
    {
        $rate = TaxRate::whereNull('archived_at')->findOrFail($id);
        $rate->forceFill(['archived_at' => now(), 'is_default' => false])->save();   // lines that used it keep pointing at it
        SecurityPolicy::audit('tax_rate_archived', 'tax_rate', $id, [], []);

        return $this->show();
    }
}
