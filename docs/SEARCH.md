# Global search (FUN-7, ENG-19)

The search box looks through matters, clients and documents. Each kind is searched only when the person may list it,
and only among the records they may see.

## Engines

| `SEARCH_ENGINE` | What it does | Needs |
|---|---|---|
| `database` (default) | Matches case numbers, names, titles and phone numbers with SQL `LIKE`, and finds words inside documents through the full-text index (FUN-8). No ranking. | Nothing extra. Fine for a firm of modest size. |
| `meilisearch` | A search engine: results ranked by relevance, tolerant of typos, with words inside documents. | A Meilisearch server (`MEILISEARCH_HOST`, `MEILISEARCH_KEY`), then `php artisan search:reindex`. |

## Per-firm indexes

Each firm has its own indexes: `<SEARCH_INDEX_PREFIX><firm>_cases`, `_clients` and `_documents`.

- **No crossover:** one firm's records are never in another firm's results or ranking statistics.
- **Deletion:** purging a firm (TEN-9) drops its indexes (`SearchPurgeStep`).
- **Sharing a cluster:** several deployments can share one Meilisearch cluster through the prefix.

What is indexed:

| Index | Fields (searched in this order of weight) | Filtered on |
|---|---|---|
| cases | case number, client name, opposing party, description | `id`, `client_id`, `access_users` |
| clients | name, phone number | `id` |
| documents | title, file name, case number, the first `SEARCH_DOCUMENT_TEXT_CHARS` (20,000) characters of the text | `case_id`, `client_id`, `access_users` |

Only the current version of a document is indexed.

## Permissions are applied by the engine

A matter's record, and each of its documents, carries `access_users`: the matter's lawyer, its creator and its
advocates. At query time the search sends the engine a filter built from the person's access (`SearchFilter`):

- **Ethical walls (SEC-11):** `client_id NOT IN [walled clients]` and `id` / `case_id NOT IN [walled matters]`.
- **Restricted matter access (SEC-10):** `(access_users = <me> OR id IN [matters granted to me])`. It is left out for
  people with `access-all-matters` and when the firm uses firm-wide access.

The engine therefore never returns, ranks or counts a record the person may not see. The rows are then read through
the normal scopes, so a stale index entry, such as a matter deleted a moment ago, is still never shown.

## Keeping the indexes current

- **On change:** saving or deleting a matter, client, document or advocate queues `IndexForSearch` on the
  interactive lane, after commit.
- **Wider re-indexing:** changing a matter's people or client re-indexes its documents. Renaming a client re-indexes
  its matters.
- **Document text:** a document is re-indexed once its text has been read (FUN-8).
- **Full rebuild:** `php artisan search:reindex [--entity=cases] [--tenant=slug]` rebuilds from the database, for
  example after enabling the engine, after a restore, or to repair.

If the engine is unreachable or answers an error, the search answers from the database instead and the error is
reported. Search keeps working, unranked.

## Operating Meilisearch

- **Run it:** `getmeili/meilisearch:v1.11` with `MEILI_MASTER_KEY`. Give the app the master key, or an API key with
  `search`, `documents.*`, `indexes.*`, `settings.update` and `tasks.get`.
- **Helm:** set `search.engine=meilisearch`, `search.meilisearchHost`, and `secrets.meilisearchKey` (or
  `MEILISEARCH_KEY` in your existing Secret).
- **Writes are asynchronous:** a new or changed record is searchable within moments, not instantly.
