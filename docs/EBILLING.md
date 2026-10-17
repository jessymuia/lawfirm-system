# UTBMS codes and LEDES e-billing (FUN-13)

Corporate and insurance clients usually accept invoices only through an e-billing system, which reads a LEDES file and
checks every line against UTBMS codes. This lets a firm produce that file from an ordinary issued invoice.

## Codes

- **Code set:** the ABA Litigation Code Set (`App\EBilling\Utbms`):
  - **task codes** L110–L530, grouped under phases L100–L500;
  - **activity codes** A101–A111;
  - **expense codes** E101–E124.
  They are a fixed public standard, so they live in code, not in a table. Firms cannot add or rename them. `GET /api/v1/utbms` lists them for forms.
- **Where codes are entered:**
  - **Time entries:** a task and an activity code (the matter's *Time* panel).
  - **Invoice lines:** task/activity codes for fees, or an expense code. A line with an expense code is an expense; any other line is a fee.
  - **Expenses:** an expense code, kept for when expenses are billed.
- **Carry-over:** *Generate invoice* copies each time entry's codes onto its invoice line.
- **Validation:** codes are optional. Only listed codes are accepted.

## Identifiers

| LEDES field | Where it comes from |
|---|---|
| `LAW_FIRM_ID` | Settings → Offices & Groups → *E-billing (LEDES)*: usually the firm's tax PIN |
| `CLIENT_ID` | the client's *E-billing client ID*, else the client's public id |
| `CLIENT_MATTER_ID` | the matter's *Client's matter reference* |
| `LAW_FIRM_MATTER_ID` | the matter number |
| `TIMEKEEPER_ID` / `_NAME` / `_CLASSIFICATION` | the person who logged the time and their *Timekeeper classification* (PT, AS, OC, LA, PL, OT) on the user form |

## Export

On an issued invoice, **E-bill (LEDES)**:

1. Checks the invoice and lists what an e-billing system would reject (`GET /api/v1/invoices/{id}/ledes/check`).
   - **Errors stop the export:**
     - a draft invoice;
     - no law firm ID;
     - a fee without task and activity codes;
     - an expense without an expense code;
     - lines that don't add up to the invoice total.
   - **Warnings do not:**
     - a timekeeper without a classification;
     - a matter without the client's reference.
2. Downloads `LEDES1998B-<invoice number>.txt` (`GET /api/v1/invoices/{id}/ledes`).

### How lines are written

- A line billed from a time entry: `F`, using the entry's hours, rate, date and timekeeper.
- A line with an expense code: `E`, dated with the invoice.
- Any other line: `F` at its own quantity and rate, attributed to the matter's responsible lawyer.
- If a line's amount differs from units × rate, the difference goes in `LINE_ITEM_ADJUSTMENT_AMOUNT`.
- **Tax:** LEDES 1998B has no tax column, so the invoice's tax is one invoice-level fee adjustment line (`IF`). `INVOICE_TOTAL` is the sum of the line totals and equals the invoice total.
- **Billing period:** from the earliest line date to the latest.
- **Reserved characters:** `|`, `[`, `]` and line breaks inside values are replaced, because they delimit fields and records.

## Not covered yet

- LEDES 1998BI (international, with tax fields) and LEDES XML 2.x.
- Code sets other than litigation (e.g. bankruptcy, project).
- Submitting to an e-billing portal: the file is uploaded by hand.
- Billing expenses straight from the expense register onto an invoice (expense lines are added as invoice lines).
