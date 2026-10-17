<?php

namespace App\Search;

use App\Jobs\IndexForSearch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ENG-19: keeps the firm's search indexes in step with its records. A record is indexed with what the engine needs to
 * decide who may see it at query time (its matter's client and the people working on it, `access_users`), so
 * permissions are never applied after the search.
 *
 * Changes are queued (`queue()`, from model events); `sync()` writes them; `rebuild()` refills an index from scratch.
 * With the database engine all of this is a no-op.
 */
class SearchIndex
{
    public const ENTITIES = ['cases', 'clients', 'documents'];

    public static function enabled(): bool
    {
        return config('search.engine') !== 'database';
    }

    /** @param list<int> $ids */
    public static function queue(string $entity, array $ids): void
    {
        if (self::enabled() && $ids) {
            IndexForSearch::dispatch($entity, array_values(array_unique(array_map('intval', $ids))))->afterCommit();
        }
    }

    /** Write the current state of these records: index what exists, remove what no longer does. */
    public function sync(string $entity, array $ids): void
    {
        $records = $this->records($entity, $ids);
        $engine = app(SearchEngine::class);
        $engine->upsert($entity, $records->values()->all());
        $engine->delete($entity, array_values(array_diff($ids, $records->keys()->all())));
    }

    /** Refill one index of the active firm. @return int records indexed */
    public function rebuild(string $entity, int $chunk = 500): int
    {
        $engine = app(SearchEngine::class);
        $engine->reset($entity);
        $n = 0;
        $this->base($entity)->select("{$entity}.id")->orderBy("{$entity}.id")
            ->chunk($chunk, function ($rows) use ($engine, $entity, &$n) {
                $records = $this->records($entity, $rows->pluck('id')->map(fn ($v) => (int) $v)->all());
                $engine->upsert($entity, $records->values()->all());
                $n += $records->count();
            });

        return $n;
    }

    /** @return Collection keyed by id: the records as the engine stores them */
    public function records(string $entity, array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }
        $rows = $this->base($entity)->whereIn("{$entity}.id", $ids)->get();

        if ($entity === 'clients') {
            return $rows->mapWithKeys(fn ($c) => [(int) $c->id => ['id' => (int) $c->id, 'name' => $c->name, 'phone_number' => $c->phone_number]]);
        }
        if ($entity === 'cases') {
            $people = $this->people($rows->pluck('id')->all());

            return $rows->mapWithKeys(fn ($c) => [(int) $c->id => ['id' => (int) $c->id, 'case_number' => $c->case_number, 'description' => $c->description,
                'opposing_party' => $c->opposing_party, 'client' => $c->client, 'client_id' => (int) $c->client_id, 'access_users' => $people[$c->id] ?? []]]);
        }
        $people = $this->people($rows->pluck('case_id')->unique()->all());
        $chars = (int) config('search.document_text_chars', 20000);

        return $rows->mapWithKeys(fn ($d) => [(int) $d->id => ['id' => (int) $d->id, 'title' => $d->title, 'filename' => $d->filename,
            'case_id' => (int) $d->case_id, 'case_number' => $d->case_number, 'client_id' => (int) $d->client_id,
            'access_users' => $people[$d->case_id] ?? [], 'content' => $d->content !== null ? mb_substr((string) $d->content, 0, $chars) : null]]);
    }

    /** Rows that belong in the index (live, current), read past every user scope. */
    private function base(string $entity): \Illuminate\Database\Query\Builder
    {
        return match ($entity) {
            'clients' => DB::table('clients')->whereNull('clients.deleted_at')->select('clients.id', 'clients.name', 'clients.phone_number'),
            'cases' => DB::table('cases')->leftJoin('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at')
                ->select('cases.id', 'cases.case_number', 'cases.description', 'cases.opposing_party', 'cases.client_id', 'clients.name as client'),
            'documents' => DB::table('documents')->join('cases', 'cases.id', '=', 'documents.case_id')
                ->leftJoin('document_texts', fn ($j) => $j->on('document_texts.document_id', '=', 'documents.id')->where('document_texts.state', 'done'))
                ->whereNull('documents.deleted_at')->whereNull('cases.deleted_at')->where('documents.is_current', true)
                ->select('documents.id', 'documents.title', 'documents.filename', 'documents.case_id', 'cases.case_number', 'cases.client_id', 'document_texts.content'),
            default => throw new \InvalidArgumentException("Unknown search entity [{$entity}]"),
        };
    }

    /** @return array<int, list<int>> matter id => the people working on it (SEC-10's membership, without grants) */
    private function people(array $caseIds): array
    {
        $out = [];
        foreach (DB::table('cases')->whereIn('id', $caseIds)->get(['id', 'assigned_to', 'created_by']) as $c) {
            $out[$c->id] = array_values(array_filter([(int) $c->assigned_to, (int) $c->created_by]));
        }
        foreach (DB::table('attorneys')->whereIn('case_id', $caseIds)->whereNull('deleted_at')->get(['case_id', 'advocate_id']) as $a) {
            $out[$a->case_id][] = (int) $a->advocate_id;
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $out);
    }
}
