# Partner reports (FUN-17)

These are the numbers a managing partner runs the firm on. Each report covers a period (`from`–`to`, at most two
years). Any report can be narrowed to an office and/or practice group, and matter profitability can be grouped by
lawyer, office or practice group (FUN-18).

## Definitions

| Measure | Definition |
|---|---|
| **Utilisation** | billable hours ÷ available hours, per lawyer. Available = working days (Mon–Fri) in the period × `REPORTS_HOURS_PER_DAY` (default 7). |
| **Billing realisation** | for time worked in the period that has been invoiced: amount its invoice lines charged ÷ its standard value (hours × rate). Below 1 means write-downs. |
| **Collection realisation** | payments received in the period ÷ fees invoiced in the period (issued invoices, before tax). |
| **WIP** | billable time not yet billed, at standard value, as at the end of the period, aged 0–30 / 31–60 / 61–90 / 90+ days from the date worked. |
| **Aged receivables** | for issued invoices up to the end date: total minus payments received by then, aged by days past the due date (not yet due / 1–30 / 31–60 / 61–90 / 90+). |
| **Matter profitability** | per matter in the period: hours, fees invoiced, collected, expenses, WIP. **Contribution** = collected − expenses. |

Notes on what counts:

- Void invoices count nowhere; drafts are not invoiced yet.
- Expenses count in the period they were incurred.
- A matter with no activity in the period is left out of profitability.

## Who sees what

- **Permission:** `view-partner-reports`.
- **Matter access:** reports only include matters the person may open (SEC-10 matter access, SEC-11 ethical walls). A
  partner restricted to their own matters sees their own numbers, never a firm total that includes matters hidden from
  them. Give firm-wide reporting to people who also have `access-all-matters`.

## Where the numbers come from

- **Connection:** reports read from the firm's read replica when one is configured and fit, and from its own database
  otherwise. Configure it with `DB_REPLICA_HOST`, a cell's `--replica-host`, or `REPORTS_DB_CONNECTION`. The page
  says when the figures are more than a minute behind. See docs/ops/READ_REPLICA.md (ENG-23).
- **Size limit:** one request covers at most `reports.max_days` (731 days).

## In the app and the API

- **In the app:** Partner Reports page with a period picker (this month / last month / year to date), office and group
  filters, headline tiles, aged receivables and WIP, utilisation by lawyer, and matter profitability. Every table
  downloads as CSV.
- **API:** `GET /api/v1/reports/{summary|utilisation|realisation|wip|receivables|profitability}?from=YYYY-MM-DD&to=YYYY-MM-DD[&office_id=][&practice_group_id=][&group_by=lawyer|office|practice_group][&format=csv]`.

## Not included

- Cost of time: there are no cost rates, so profitability is contribution (collected − expenses), not margin after
  salaries.
- Multi-currency totals: amounts are summed as recorded (DATA-14 adds currency handling).
