<?php

namespace App\Search;

use App\Documents\DocumentSearch;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * FUN-7 / ENG-19: the search box. Each kind of record is searched only if the person may list it, and only among the
 * records they may see.
 *
 * With a search engine the engine ranks the results and applies the person's walls and matter access as a filter
 * while it searches (SearchFilter). The rows are then read through the normal scopes, so a record whose index entry
 * is stale is still never shown. Should the engine fail, the search falls back to SQL rather than failing.
 */
class GlobalSearch
{
    private const LIMIT = 10;

    public function __construct(private SearchEngine $engine) {}

    /** @return array{cases: Collection, clients: Collection, documents: Collection, engine: string} */
    public function search(User $user, string $term): array
    {
        if (SearchIndex::enabled()) {
            try {
                return $this->engine($user, $term) + ['engine' => (string) config('search.engine')];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->database($term) + ['engine' => 'database'];
    }

    private function engine(User $user, string $term): array
    {
        $out = ['cases' => collect(), 'clients' => collect(), 'documents' => collect()];
        if (Gate::allows('viewAny', Cases::class)) {
            $hits = $this->engine->search('cases', $term, SearchFilter::for($user, 'id'), self::LIMIT);
            $out['cases'] = $this->ordered($hits, Cases::whereIn('id', array_column($hits, 'id'))->get(['id', 'case_number', 'description']))
                ->map(fn ($row) => $this->caseRow($row));
        }
        if (Gate::allows('viewAny', Client::class)) {
            $hits = $this->engine->search('clients', $term, SearchFilter::for($user, null), self::LIMIT);
            $out['clients'] = $this->ordered($hits, Client::whereIn('id', array_column($hits, 'id'))->get(['id', 'public_id', 'name', 'phone_number']))
                ->map(fn ($row) => $this->clientRow($row));
        }
        if (Gate::allows('viewAny', Document::class)) {
            $hits = $this->engine->search('documents', $term, SearchFilter::for($user, 'case_id'), self::LIMIT);
            $snippets = array_column($hits, 'snippet', 'id');
            $out['documents'] = $this->ordered($hits, Document::with('case')->whereIn('id', array_column($hits, 'id'))->get())
                ->map(fn ($row) => $this->documentRow($row, $snippets[$row->id] ?? null));
        }

        return $out;
    }

    /** Rows in the engine's order; ids the scopes withheld simply drop out. */
    private function ordered(array $hits, Collection $rows): Collection
    {
        $byId = $rows->keyBy('id');

        return collect($hits)->map(fn ($h) => $byId->get($h['id']))->filter()->values();
    }

    /** The SQL search (no engine): names and titles by LIKE, plus words inside documents (FUN-8). */
    private function database(string $term): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $out = ['cases' => collect(), 'clients' => collect(), 'documents' => collect()];

        if (Gate::allows('viewAny', Cases::class)) {
            $out['cases'] = Cases::where('case_number', 'like', $like)->orWhere('description', 'like', $like)->orWhere('opposing_party', 'like', $like)
                ->orderBy('id', 'desc')->limit(self::LIMIT)->get(['id', 'case_number', 'description'])->map(fn ($row) => $this->caseRow($row));
        }
        if (Gate::allows('viewAny', Client::class)) {
            $out['clients'] = Client::where('name', 'like', $like)->orWhere('phone_number', 'like', $like)
                ->orderBy('id', 'desc')->limit(self::LIMIT)->get(['id', 'public_id', 'name', 'phone_number'])->map(fn ($row) => $this->clientRow($row));
        }
        if (Gate::allows('viewAny', Document::class)) {
            $docs = Document::with('case')->where('title', 'like', $like)->orWhere('filename', 'like', $like)
                ->orderBy('id', 'desc')->limit(self::LIMIT)->get()->map(fn ($row) => $this->documentRow($row, null));
            // FUN-8: and documents whose text contains the words, quoting where
            $seen = $docs->pluck('id')->flip();
            $inside = collect(app(DocumentSearch::class)->search($term, null, 1, self::LIMIT)['data'])
                ->reject(fn ($d) => isset($seen[$d['id']]))
                ->map(fn ($d) => ['id' => $d['id'], 'title' => $d['title'] ?: $d['filename'], 'subtitle' => ($d['case_number'] ?? 'Unknown case').' · '.$d['snippet'],
                    'case_id' => $d['case_id'], 'type' => 'document']);
            $out['documents'] = $docs->concat($inside)->take(self::LIMIT)->values();
        }

        return $out;
    }

    private function caseRow(Cases $row): array
    {
        return ['id' => $row->id, 'title' => $row->case_number, 'subtitle' => $row->description, 'type' => 'case'];
    }

    private function clientRow(Client $row): array
    {
        return ['id' => $row->id, 'public_id' => $row->publicId(), 'title' => $row->name, 'subtitle' => $row->phone_number, 'type' => 'client'];
    }

    private function documentRow(Document $row, ?string $snippet): array
    {
        $case = $row->case->case_number ?? 'Unknown case';

        return ['id' => $row->id, 'title' => $row->title ?: $row->filename, 'subtitle' => $snippet ? $case.' · '.$snippet : $case,
            'case_id' => $row->case_id, 'type' => 'document'];
    }
}
