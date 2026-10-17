# OpenAPI document and typed client (ENG-12)

The staff API is described by a generated document, `docs/openapi.json` (OpenAPI 3.0). That is every `/api/v1` route except `/api/v1/public/*`; the public API keeps its own hand-written spec, `docs/openapi-public.yaml`. The web app has a typed client generated from it, `resources/js/src/api/client.generated.ts`.

Neither file is edited by hand:

    php artisan api:openapi           # regenerate both after changing routes, Form Requests or Resources
    php artisan api:openapi --check   # what CI runs: fails if either file is out of date

## Where each part comes from

| In the document | Source |
| --- | --- |
| Paths, methods, path parameters (integer when the route constrains them to digits) | the registered routes |
| `operationId` (`invoiceStore`, `financeSettingsTaxRatePut`, …) | controller and method; a method suffix when one action serves several routes |
| `x-authz` | the route's `authz:*` declaration. `permission,<name>`, `authenticated` and `policy,…` are for staff; `portal-session` is the client portal's own session; `public` means the request carries its own credential (a signed link, a webhook secret, a one-time code) or needs none (sign-in) |
| Request body (JSON, or multipart when a field is a file) or GET query | the action's Form Request: `rules()` become types, required fields, `nullable`, enums (`in:`, `Rule::in`, `Rule::enum`), lengths and nested objects/lists |
| Answer | the usual envelope (`ApiResponse`) around the Resource the action returns (`components.schemas`, from `Resource::SHAPE`): one record, a list, or a page (`Paginated`) for list endpoints |

**What the generator cannot know is described generically, not guessed:**

- the query filters of list endpoints;
- the answers of actions that build their JSON by hand (reports, settings screens): `data` has no fixed schema.

**Rules must not depend on the database.** A Form Request's `rules()` must be readable outside a request and without a database, so the document is the same in every environment. A rule that depends on firm data (the firm's currencies, a record's state) belongs in a rule object that checks at validation time, such as `App\Rules\FirmCurrency`.

## The client

There is one function per operation, in this argument order:

1. path parameters first (a reserved word gets `Id` appended: `casesDestroy(caseId)`);
2. then the typed body, or `params` for a GET;
3. then axios options.

Each resolves to the typed envelope:

```ts
import { invoiceStore, financeSettingsTaxRate } from '@/api/client.generated';

const { data: invoice } = await invoiceStore({ case_id, client_id, invoice_date, invoice_due_date, currency: 'USD' });   // Invoice
await financeSettingsTaxRate({ name: 'VAT', rate: 16, jurisdiction: 'KE' });   // body checked against the Form Request
```

The client goes through the app's axios instance (`@/api`), so the session cookie, the XSRF token and the 401/read-only handling all apply. Settings → Currencies and tax uses it. Existing screens move over as they are touched; `npm run type-check` then checks their requests and answers against the server.

## Publishing

- **CI:** runs `api:openapi --check` and uploads `docs/openapi.json` as the `openapi` artifact of every run.
- **Releases:** the document ships in the repository at the released tag.
