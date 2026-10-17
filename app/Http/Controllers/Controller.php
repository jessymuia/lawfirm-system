<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * ENG-5: this exact helper was copy-pasted into 21 separate
     * controllers with only whitespace differences. One shared
     * implementation here; child controllers no longer declare their own.
     */
    protected function response(bool $success, string $message, $data, int $statusCode): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * FUN-1: every index() previously called Model::all(), loading the
     * entire table into memory and the response on every request.
     *
     * The current frontend tables (v-client-table) still expect a flat
     * array and paginate client-side, so we can't switch straight to
     * server-side-only pagination without silently hiding rows from users
     * until that frontend rebuild lands (tracked under UI-5). Until then:
     *
     *  - if the request includes `?page=`, we paginate server-side and
     *    return Laravel's standard paginator shape (ready for the UI-5
     *    frontend to adopt whenever it lands);
     *  - otherwise we return the full, mapped collection as before, so
     *    today's UI keeps working unchanged.
     *
     * In both cases the caller is expected to build $query with eager
     * loading already applied, so this never reintroduces N+1 queries.
     *
     * $sortableColumns / $searchableColumns are explicit whitelists the
     * caller opts into per module — `sort`/`direction`/`search` request
     * params are only ever applied to a column name that's actually in
     * that whitelist, so a column name from the request is never
     * interpolated into the query unchecked (avoids both SQL injection
     * and sorting/filtering on a column the caller didn't intend to
     * expose, e.g. a foreign key id instead of the human-readable name).
     */
    /**
     * ENG-16: id + display name of a small reference list (court types, hearing types, expense categories) for a
     * dropdown. Capped and searchable, so it can never become a full-table load; lists that can grow use paging.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): string  $label
     */
    protected function optionsResponse(Request $request, Builder $query, callable $label, int $cap = 500): JsonResponse
    {
        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('search')).'%';
            $model = $query->getModel();
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->when(Schema::hasColumn($model->getTable(), 'alias'), fn ($q) => $q->orWhere('alias', 'like', $term)));
        }
        $rows = $query->orderBy('name')->limit($cap + 1)->get();

        return response()->json([
            'success' => true,
            'data' => $rows->take($cap)->map(fn ($r) => ['id' => $r->getKey(), 'name' => $label($r)])->values(),
            'truncated' => $rows->count() > $cap,   // the caller should switch to search
        ]);
    }

    protected function paginatedOrFull(
        Request $request,
        Builder $query,
        callable $mapRow,
        int $defaultPerPage = 25,
        array $sortableColumns = [],
        array $searchableColumns = []
    ) {
        if ($request->filled('search') && ! empty($searchableColumns)) {
            $search = $request->input('search');
            $query->where(function ($q) use ($searchableColumns, $search) {
                foreach ($searchableColumns as $column) {
                    $q->orWhere($column, 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%');
                }
            });
        }

        if ($request->filled('sort') && in_array($request->input('sort'), $sortableColumns, true)) {
            $direction = strtolower($request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
            // reorder() clears any default ordering the caller already
            // applied (e.g. ->orderBy('id', 'desc')) before adding this
            // one — Eloquent's orderBy() otherwise *accumulates* orders,
            // so a requested sort would silently lose to the caller's
            // default rather than replacing it.
            $query->reorder()->orderBy($request->input('sort'), $direction);
        }

        // ENG-16: always server-paginated. The old "no ?page= means the whole table" branch is gone: it was the
        // biggest latency/memory cliff in the app. Callers that need every row of a bounded set (one case's hearings,
        // one invoice's payments) walk the pages; dropdowns use the capped `options` endpoints.
        $perPage = (int) $request->input('per_page', $defaultPerPage);
        $perPage = max(1, min($perPage, 100));

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform($mapRow);

        return $paginated;
    }

    /**
     * FUN-4: stream a CSV response without buffering the whole file in
     * memory — safe for firm-wide exports that could otherwise be
     * thousands of rows.
     */
    protected function streamCsv(string $filename, array $headerRow, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headerRow, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headerRow);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
