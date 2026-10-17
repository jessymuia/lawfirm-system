# Currencies, exchange rates and tax (DATA-14)

## The base currency

A firm keeps its books in one **base currency**. Time rates, expenses and every report are in it.

- **Choosing it:** Settings → Currencies and tax (permission `manage-finance-settings`). The install default is `MONEY_BASE_CURRENCY`, `KES` unless set.
- **Changing it:** possible only until the first invoice is issued with a fixed rate. After that the books are in it, so the base currency is locked.

## Invoices in other currencies

An invoice is in the base currency unless it is drafted in another enabled currency. Its lines, totals and payments are all in that currency, which is what the client pays.

### Exchange rates

An exchange rate says how many units of the base currency one unit of a currency was worth from a date. For example, `USD 131.25 from 2026-03-01` means 1 USD = 131.25 KES from that day.

Rates are entered under Settings → Currencies and tax, or `POST /api/v1/finance/exchange-rates`.

### The rate is fixed when the invoice is issued

Issuing an invoice (`send-to-admin`) fixes:

- the latest rate on or before the **invoice date** (`fx_rate`, and `fx_rate_on`, the date that rate took effect);
- its totals in the base currency at that rate (`base_subtotal`, `base_total_amount`);
- the base currency itself (`base_currency`).

After that the rate never changes, whatever later rates say. Reports therefore add up issued invoices the same way every time, and they match what was sent to the client.

**An invoice in a currency with no rate on or before its date cannot be issued.** The API answers 422 with `code: missing_exchange_rate`; add a rate, then issue it.

### Invoices issued before this release

`BackfillInvoiceFx` is queued by `app:upgrade` and by the fleet migrator.

- Issued invoices in the base currency get rate 1, since their totals already are in it.
- Issued invoices in another currency keep no rate. None was recorded when they were issued, and guessing one would rewrite history. Reports list them as **unconverted** (below).

## Tax rates

Tax rates are the firm's tax rules: a name, a percentage, an optional jurisdiction, and one default. Examples: `VAT 16% KE` and `Zero-rated 0%`.

- **A line with a tax rate:** its tax is `quantity × rate × percentage`, worked out on the server. The percentage is kept on the line (`tax_rate_percent`), so the line still says what it was charged under if the rate later changes.
- **A line without one:** its tax is the amount given, as before.
- **New lines:** the default rate is preselected in the web app.

Tax rates are archived, never deleted, because lines point at them. An archived rate cannot be used on new lines.

## Reports in one currency

Partner reports and the dashboard are in the base currency, each invoice converted at the rate it fixed. Payments are converted at their invoice's rate. Each report states its currency.

**Invoices with no rate are never added at face value.** Issued invoices without a rate are left out of the converted totals and listed separately as `unconverted`: their currency, how many, and the amount in that currency.

For aged receivables, each invoice shows:

- what is owed in its own currency (`outstanding_in_currency`);
- that amount in the base currency (`outstanding`).

## API

| Method and path | Permission | What |
| --- | --- | --- |
| `GET /api/v1/finance/settings` | `list-invoice` | Base currency, currencies, latest rates, active tax rates |
| `PUT /api/v1/finance/base-currency` | `manage-finance-settings` | Choose the base currency; 409 `base_currency_locked` once invoices are issued |
| `POST /api/v1/finance/currencies` | `manage-finance-settings` | Add, or enable or disable, a currency |
| `POST /api/v1/finance/exchange-rates` | `manage-finance-settings` | A rate from a date (replaces one for the same date) |
| `DELETE /api/v1/finance/exchange-rates/{id}` | `manage-finance-settings` | Remove a mistaken rate; issued invoices keep theirs |
| `POST /api/v1/finance/tax-rates`, `PUT …/{id}`, `POST …/{id}/archive` | `manage-finance-settings` | Tax rates |

`POST /api/v1/invoices` takes an optional `currency`. `POST /api/v1/invoiceItems` takes `tax_rate_id` in place of `tax`.
