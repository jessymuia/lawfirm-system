<?php

namespace App\Money;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Support\FirmSettings;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-14: the firm's currencies and exchange rates (docs/MONEY.md).
 *
 * The firm keeps its books in one base currency. An invoice may be in another; when it is issued, the latest rate on
 * or before its invoice date is fixed on it (fx_rate, with the date that rate took effect) together with its totals
 * in the base currency. That rate never changes afterwards, whatever later rates say, so reports always add up issued
 * invoices the same way. An invoice in a currency with no rate cannot be issued.
 */
class Money
{
    public const SETTING = 'money.base_currency';

    public function baseCurrency(): string
    {
        return strtoupper((string) (FirmSettings::get(self::SETTING) ?: config('money.base_currency', 'KES')));
    }

    /** @return list<string> codes the firm can invoice in: its enabled currencies, and always its base currency */
    public function currencies(): array
    {
        $codes = Schema::hasTable('currencies') ? Currency::where('enabled', true)->orderBy('code')->pluck('code')->all() : [];

        return array_values(array_unique([$this->baseCurrency(), ...$codes]));
    }

    /** @return array{0: float, 1: string}|null the rate to the base currency in force on $date, and the date it took effect */
    public function rateOn(string $currency, string $date): ?array
    {
        $base = $this->baseCurrency();
        if (strtoupper($currency) === $base) {
            return [1.0, $date];
        }
        $row = ExchangeRate::where('currency', strtoupper($currency))->where('base_currency', $base)
            ->whereDate('effective_on', '<=', $date)->orderByDesc('effective_on')->first();

        return $row ? [(float) $row->rate, $row->effective_on->toDateString()] : null;
    }

    /**
     * The exchange rate and base-currency totals an invoice fixes when it is issued.
     *
     * @return array{base_currency: string, fx_rate: float, fx_rate_on: string, base_subtotal: float, base_total_amount: float}
     *
     * @throws MissingExchangeRate
     */
    public function fixFor(Invoice $invoice): array
    {
        $currency = strtoupper((string) ($invoice->currency ?: $this->baseCurrency()));
        $date = substr((string) $invoice->invoice_date, 0, 10);   // stored as Y-m-d (no date cast on invoices)
        $rate = $this->rateOn($currency, $date) ?? throw new MissingExchangeRate($currency, $this->baseCurrency(), $date);

        return ['base_currency' => $this->baseCurrency(), 'fx_rate' => $rate[0], 'fx_rate_on' => $rate[1],
            'base_subtotal' => round((float) $invoice->subtotal * $rate[0], 2), 'base_total_amount' => round((float) $invoice->total_amount * $rate[0], 2)];
    }

    /**
     * SQL for "this invoice's rate to the base currency": the rate it fixed when issued, or 1 for an invoice in the base
     * currency issued before rates were fixed; NULL (left out of totals, and reported as unconverted) otherwise.
     *
     * @return array{0: string, 1: list<string>} the expression and its bindings
     */
    public function rateSql(string $alias): array
    {
        return ["COALESCE({$alias}.fx_rate, CASE WHEN {$alias}.currency = ? OR {$alias}.currency IS NULL THEN 1 END)", [$this->baseCurrency()]];
    }
}
