# TypeScript on the API boundary (ENG-15)

The web app is moving to TypeScript one file at a time, starting where mistakes cost the most: the boundary with the
API. `npm run type-check` (vue-tsc) is a CI gate.

## Generated API types

Each v1 API Resource (`app/Http/Resources/V1/*Resource.php`) declares its shape as a `SHAPE` constant, key by key:
`int`, `string`, `bool`, `decimal`, `date` and `int[]`, with a leading `?` for nullable values.

- **Generate:** `php artisan api:types` writes `resources/js/src/api/types.generated.ts`. That file holds one interface
  per Resource (`Matter`, `Invoice`, `TimeEntry`, …) plus `ApiResponse<T>` and `Paginated<T>`.
- **Import:** `import type { Invoice, ApiResponse, Paginated } from '@/api'`.

Two checks keep the types true:

- **`ApiShapesTest` (backend CI):**
  - It renders every Resource for real records and fails if the JSON has a key, an order or a type its `SHAPE` does
    not declare.
  - It fails if the generated file is stale. Run `php artisan api:types` and commit.
- **`npm run type-check` (frontend CI):** it fails when TypeScript code uses a field the API does not send, for
  example a column renamed on the server.

Changing an API shape: edit the Resource and its `SHAPE`, run `php artisan api:types`, then fix what `type-check`
reports.

## What is TypeScript today

- **`resources/js/src/api.ts`:** the API client (axios instance, `fetchAllPages`, `uploadDocument`, replica lag
  helpers), fully typed.
- **`views/menu/time-entries/index.vue`:** the pattern for a component. Its table columns are `keyof TimeEntry`.

Everything else is JavaScript and keeps working unchanged. `tsconfig.json` uses `allowJs` with `checkJs: false`, so JS
files are not checked until they opt in.

## Migrating a component

1. Change `<script setup>` to `<script setup lang="ts">`.
2. Type what crosses the API:
   - `axios.get<ApiResponse<Paginated<Invoice>>>(…)`
   - `ref<Invoice | null>(null)`
   - props with `defineProps<{ invoice: Invoice }>()`
3. Run `npm run type-check` and fix what it finds. Prefer narrowing (`if (x)`) over `as`. Use `as` only where an
   untyped child, such as the data table's slot rows, hands over `unknown` (see `entry(row)` in the time-entries
   page).
4. ESLint already parses `lang="ts"`. Commit with the component's behaviour unchanged.

Order of migration: components that read and write money (invoices, payments, trust), then forms that post to the
API, then lists. A `.js` module becomes `.ts` by renaming it, since imports resolve either extension.

## The generated client (ENG-12)

`resources/js/src/api/client.generated.ts` has a typed function for every staff endpoint, generated from the OpenAPI
document (docs/OPENAPI.md). Prefer it over hand-written `axios` calls in new and migrated code. Its request bodies are
typed from the server's Form Requests, so `type-check` catches a field the server would reject.

## Not covered yet

Endpoints that answer something other than a Resource (reports, dashboards, search) are typed where they are used:
their `data` has no fixed schema in the document.
