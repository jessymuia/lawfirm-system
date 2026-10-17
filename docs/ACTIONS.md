# Actions (ENG-9)

Business operations live in `app/Actions`, one class per operation. They are the only code that changes matters,
clients, invoices, payments, trust money, time, hearings and documents. Controllers call them. So do jobs, scheduled
commands, imports and integrations. Because every caller goes through the same action, the same rules apply whichever
way the operation is triggered.

## The contract

1. **The caller authorizes; the action enforces business rules.**
   - Routes authorize before the controller runs (`authz:*`, ENG-11), and so do policies. Matter access is checked when
     a model is loaded (`MatterScope`).
   - An action receives models its caller has already loaded and is allowed to change.
   - It refuses what no one may do: issuing an invoice twice, overpaying, overdrawing trust money, editing billed time.
2. **Who acted is an argument.** Every action takes `?User $actor`. Pass `null` for the system (a job, the console, a
   webhook). Actions never read `Auth::`, `request()` or `Request`; `ActionsArchitectureTest` enforces this.
3. **Data in canonical form.** Dates are `Y-m-d`, times are `H:i`, amounts are numbers. Translating what the screens
   send (for example `d/m/Y`) is the controller's job.
4. **A refusal is an `App\Actions\ActionException`.** It carries a message and a status (422 unless stated otherwise),
   and optionally a machine-readable `code`. Over HTTP it renders as the API's usual
   `{success: false, message, data: null}`, with the code added when there is one.
5. **Consistency is the action's job.** Each action wraps its changes in one transaction. It locks the rows its rule
   depends on, so two requests arriving at once cannot break the rule between them:
   - the invoice row when a payment is recorded;
   - a client's trust entries when money is posted or voided;
   - a document's versions when a version is added or deleted;
   - unbilled time when it is billed.

## The actions

| Area | Action | What it does |
|---|---|---|
| Matters | `OpenMatter` | Opens a matter, after conflict clearance when given or required (FUN-9). Defaults the office and practice group to the lawyer's (FUN-18). Adds the opposing party to the register. |
| | `UpdateMatter` | Changes a matter's details; the opposing party is re-registered. |
| | `ChangeMatterStatus` | Moves a matter through its allowed statuses only. |
| | `DeleteMatters` | Deletes matters (soft), all or nothing; `restore()` undoes it. |
| Clients | `RegisterClient` | Takes on a client, after conflict clearance; sends the welcome SMS. |
| | `UpdateClient` | Changes a client's details. |
| | `DeleteClients` | Deletes clients (soft), all or nothing (legal hold, SEC-13); `restore()` undoes it. |
| Invoices | `DraftInvoice` | Starts a draft invoice. |
| | `ReviseDraftInvoice` | Changes a draft's details. |
| | `ChangeInvoiceLines` | Adds, changes or removes a draft's lines. Line totals are calculated, never taken from the caller, and the invoice totals follow. |
| | `SubmitInvoice` | Issues the invoice; from then on its lines are frozen. |
| | `VoidInvoice` | Marks the invoice void and deletes it (soft). |
| Payments | `RecordPayment` | Records money against an issued invoice, never above the balance. Online payments (FUN-11) are capped at the balance instead of refused. |
| | `RemovePayment` | Removes a payment (soft). |
| Trust | `PostTrustEntry` | Posts a deposit or a disbursement; a disbursement never overdraws the client. |
| | `VoidTrustEntry` | Voids an entry (never edits it, DATA-10). A deposit whose money is spent cannot be voided. |
| Time | `RecordTime` | Logs, corrects and removes time. Billed time is frozen. |
| | `BillTime` | Turns a matter's unbilled billable time into a draft invoice, one line per entry, UTBMS codes included (FUN-13). |
| Hearings | `ScheduleHearing` | Schedules, changes, cancels and restores hearings. |
| | `RecordHearingOutcome` | Records the outcome and, if given, the next date in the same court (FUN-19). |
| Documents | `StoreDocument` | The single way a document is filed: staff uploads, client portal uploads (FUN-10), email attachments (FUN-15), assembled templates and signature certificates (FUN-14). It writes to the firm's disk under its prefix, records a SHA-256, versions documents and triggers text extraction for search. |
| | `DeleteDocumentVersion` | Deletes one version and makes the latest remaining version current. |

## Using one

```php
// a controller: authorize (the route already did), translate the request, call, answer
$matter = app(OpenMatter::class)->handle($this->matterData($request), Auth::user(), $request->integer('conflict_check_id') ?: null);

// a job or command: same rules, no person
$invoice = app(BillTime::class)->handle($caseId, $clientId, null);
```

## Adding one

- Put it in `app/Actions/<Area>/<Verb><Noun>.php`, with a `handle()` method, or with named verbs where they read
  better (`log` / `correct` / `remove`).
- Use `Concerns\Stamps` to record who acted (`updated_by`, `deleted_by`).
- Test it at the action level, with no request and no one signed in (`tests/Feature/ActionsTest.php`). Keep the HTTP
  tests too, for what the controller adds: authorization and input translation.
- If the controller it serves is in `ActionsArchitectureTest::CONTROLLERS`, that test fails while the write is still in
  the controller. Add new domain controllers to that list.
