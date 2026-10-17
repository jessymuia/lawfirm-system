<?php

namespace App\Documents;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FUN-8: search inside documents. Each firm's text lives in its own database, so the index is per firm by construction.
 * Matter access and ethical walls (SEC-10) are applied in the query itself, through the Document model's scope, so a
 * document the searcher may not open is never matched, counted or quoted. Only current versions are searched.
 *
 * MySQL/MariaDB use the full-text index in boolean mode: every word must appear (as a word or the start of one),
 * "quoted phrases" must appear as written, and results are ranked by relevance. Other databases fall back to a scan with
 * the same every-word rule. ENG-19 can swap in a search engine behind search().
 */
class DocumentSearch
{
    private const SNIPPET = 220;

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, engine: string}
     */
    public function search(string $q, ?int $caseId = null, int $page = 1, int $perPage = 20): array
    {
        $q = trim($q);
        $words = $this->words($q);
        $query = Document::query()->select('documents.*')
            ->join('document_texts', 'document_texts.document_id', '=', 'documents.id')
            ->where('documents.is_current', true)->where('document_texts.state', 'done')
            ->when($caseId, fn (Builder $b) => $b->where('documents.case_id', $caseId));

        $engine = $this->fullText() ? 'fulltext' : 'scan';
        if ($engine === 'fulltext') {
            $against = $this->booleanQuery($q);
            $query->whereRaw('MATCH(document_texts.content) AGAINST (? IN BOOLEAN MODE)', [$against])
                ->selectRaw('MATCH(document_texts.content) AGAINST (? IN BOOLEAN MODE) as relevance', [$against])
                ->orderByDesc('relevance')->orderByDesc('documents.id');
        } else {
            foreach ($words as $w) {
                $query->where('document_texts.content', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $w).'%');
            }
            $query->orderByDesc('documents.id');
        }

        $total = (clone $query)->count('documents.id');
        $rows = $query->with('case:id,case_number')->addSelect(['document_texts.content as text_content', 'document_texts.method as text_method'])
            ->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(fn ($d) => [
                'id' => (int) $d->id, 'title' => $d->title, 'filename' => $d->filename, 'case_id' => (int) $d->case_id,
                'case_number' => $d->case?->case_number, 'ocr' => $d->getAttribute('text_method') === 'ocr',
                'snippet' => $this->snippet((string) $d->getAttribute('text_content'), $words),
            ])->all(),
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'engine' => $engine,
        ];
    }

    /** Text-reading state for one matter's documents (what is searchable, what needs OCR). @return array<int, array<string, mixed>> */
    public function states(int $caseId): array
    {
        return Document::query()->leftJoin('document_texts', 'document_texts.document_id', '=', 'documents.id')
            ->where('documents.case_id', $caseId)->where('documents.is_current', true)
            ->toBase()   // plain rows, matter-access scope still applied
            ->get(['documents.id', 'document_texts.state', 'document_texts.method', 'document_texts.chars', 'document_texts.error'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => ['state' => $r->state ?? 'pending', 'method' => $r->method, 'chars' => (int) $r->chars, 'error' => $r->error]])
            ->all();
    }

    /**
     * The search as a boolean full-text query: +"exact phrase" for quoted parts, +word* for the rest. Operators typed by
     * the user are not passed through.
     */
    private function booleanQuery(string $q): string
    {
        $terms = [];
        $rest = (string) preg_replace_callback('/"([^"]+)"/u', function ($m) use (&$terms) {
            $phrase = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $m[1]));
            $phrase !== '' && $terms[] = '+"'.$phrase.'"';

            return ' ';
        }, $q);
        foreach (preg_split('/\s+/', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $rest)) ?: [] as $w) {
            if (mb_strlen($w) >= 2) {
                $terms[] = '+'.$w.'*';
            }
        }

        return implode(' ', $terms);
    }

    /** @return list<string> */
    private function words(string $q): array
    {
        $clean = (string) preg_replace('/[^\p{L}\p{N}\s\'-]+/u', ' ', $q);

        return array_values(array_unique(array_filter(preg_split('/\s+/', mb_strtolower($clean)) ?: [], fn ($w) => mb_strlen($w) >= 2)));
    }

    /** A passage around the first word found, with the matching words marked as «…». */
    private function snippet(string $text, array $words): string
    {
        $lower = mb_strtolower($text);
        $at = null;
        foreach ($words as $w) {
            $p = mb_strpos($lower, $w);
            if ($p !== false && ($at === null || $p < $at)) {
                $at = $p;
            }
        }
        $start = max(0, ($at ?? 0) - intdiv(self::SNIPPET, 3));
        $part = (string) preg_replace('/\s+/u', ' ', mb_substr($text, $start, self::SNIPPET));
        foreach ($words as $w) {
            $part = (string) preg_replace('/('.preg_quote($w, '/').')/iu', '«$1»', $part);
        }

        return ($start > 0 ? '…' : '').trim($part).($start + self::SNIPPET < mb_strlen($text) ? '…' : '');
    }

    private function fullText(): bool
    {
        $engine = (string) config('documents.search.engine', 'auto');

        return $engine === 'fulltext' || ($engine === 'auto' && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true));
    }
}
