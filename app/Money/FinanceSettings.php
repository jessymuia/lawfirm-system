<?php

namespace App\Money;

use App\Actions\ActionException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\TaxRate;
use App\Support\FirmSettings;
use Illuminate\Support\Facades\DB;

/** DATA-14: changes to the firm's money settings (Settings → Currencies and tax). Every change is audited by the caller. */
class FinanceSettings
{
    public function __construct(private Money $money) {}

    /** @return array<string, mixed> everything the settings screen and the invoice form need */
    public function overview(): array
    {
        $base = $this->money->baseCurrency();
        $latest = ExchangeRate::where('base_currency', $base)->orderByDesc('effective_on')->get()->unique('currency')->values();

        return [
            'base_currency' => $base,
            'base_currency_locked' => $this->baseLocked(),
            'numbering' => $this->numbering(),
            'currencies' => Currency::orderBy('code')->get(['code', 'name', 'minor_units', 'symbol', 'enabled', 'is_system']),
            'invoice_currencies' => $this->money->currencies(),
            'exchange_rates' => $latest->map(fn ($r) => ['id' => $r->id, 'currency' => $r->currency, 'rate' => (float) $r->rate,
                'effective_on' => $r->effective_on->toDateString(), 'source' => $r->source])->all(),
            'tax_rates' => TaxRate::whereNull('archived_at')->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'rate', 'jurisdiction', 'is_default', 'is_system'])->map(fn ($t) => ['rate' => (float) $t->rate] + $t->toArray())->all(),
        ];
    }

    /** DATA-8: how this firm's invoice, receipt and trust-ledger numbers look. Numbers stay in one unbroken run whatever is changed here. */
    public function numbering(): array
    {
        $perYear = (bool) FirmSettings::get('numbering.per_year', (bool) config('invoicing.number_per_year', false));
        $out = ['per_year' => $perYear, 'prefixes' => [], 'examples' => []];
        foreach (['invoice' => 'INV', 'receipt' => 'RCT', 'trust' => 'TRT'] as $name => $default) {
            $custom = FirmSettings::get("numbering.prefix.{$name}");
            $prefix = is_string($custom) && $custom !== '' ? $custom : $default;
            $out['prefixes'][$name] = $prefix;
            $out['examples'][$name] = $perYear ? "{$prefix}-".now()->year.'-000123' : "{$prefix}-000123";
        }

        return $out;
    }

    /** @param array{per_year: bool, prefixes: array<string,string>} $d */
    public function saveNumbering(array $d, ?int $by): array
    {
        FirmSettings::set('numbering.per_year', (bool) $d['per_year'], $by);
        foreach (['invoice', 'receipt', 'trust'] as $name) {
            if (isset($d['prefixes'][$name])) {
                FirmSettings::set("numbering.prefix.{$name}", strtoupper($d['prefixes'][$name]), $by);
            }
        }

        return $this->numbering();
    }

    /** The base currency can be chosen until the first invoice fixes a rate against it: after that the books are in it. */
    public function baseLocked(): bool
    {
        return DB::table('invoices')->whereNotNull('fx_rate')->exists();
    }

    public function setBaseCurrency(string $code, ?int $by): void
    {
        $code = strtoupper($code);
        if ($code !== $this->money->baseCurrency() && $this->baseLocked()) {
            throw new ActionException('Invoices have already been issued in the current base currency, so it cannot change.', 409, 'base_currency_locked');
        }
        FirmSettings::set(Money::SETTING, $code, $by);
    }

    /** @param array{code: string, name: string, minor_units?: int, symbol?: ?string, enabled?: bool} $d */
    public function saveCurrency(array $d): Currency
    {
        $existing = Currency::where('code', strtoupper($d['code']))->first();
        if ($existing?->is_system) {
            // TEN-11: a catalog currency's name and minor units are the platform's; the firm only turns it on or off
            $existing->update(['enabled' => $d['enabled'] ?? $existing->enabled]);

            return $existing;
        }

        return Currency::updateOrCreate(['code' => strtoupper($d['code'])],
            ['name' => $d['name'], 'minor_units' => $d['minor_units'] ?? 2, 'symbol' => $d['symbol'] ?? null, 'enabled' => $d['enabled'] ?? true]);
    }

    /** @param array{currency: string, rate: float|string, effective_on: string, source?: string} $d */
    public function saveRate(array $d, ?int $by): ExchangeRate
    {
        $currency = strtoupper($d['currency']);
        $base = $this->money->baseCurrency();
        if ($currency === $base) {
            throw new ActionException("{$base} is the base currency: its rate is always 1.", 422, 'base_currency_rate');
        }

        return ExchangeRate::updateOrCreate(['currency' => $currency, 'base_currency' => $base, 'effective_on' => $d['effective_on']],
            ['rate' => $d['rate'], 'source' => $d['source'] ?? 'manual', 'created_by' => $by]);
    }

    /** @param array{name: string, rate: float|string, jurisdiction?: ?string, is_default?: bool} $d */
    public function saveTaxRate(array $d, ?TaxRate $rate, ?int $by): TaxRate
    {
        $changes = $rate?->is_system ? array_filter([
            'name' => $d['name'] !== $rate->name, 'rate' => (float) $d['rate'] !== (float) $rate->rate,
            'jurisdiction' => ($d['jurisdiction'] ?? $rate->jurisdiction) !== $rate->jurisdiction,
        ]) : [];
        if ($changes) {
            // TEN-11: a catalog rate's name and percentage follow the law, kept current by the platform; the firm may make it
            // its default or archive it, nothing else
            throw new ActionException('This is a system tax rate: only whether it is the default can change here. Add your own rate instead.', 409, 'system_catalog');
        }

        return DB::transaction(function () use ($d, $rate, $by) {
            if (! empty($d['is_default'])) {
                TaxRate::where('is_default', true)->update(['is_default' => false]);   // one default at a time
            }
            $rate ??= new TaxRate(['created_by' => $by]);
            $rate->fill(array_intersect_key($d, array_flip(['name', 'rate', 'jurisdiction', 'is_default'])))->save();

            return $rate;
        });
    }
}
