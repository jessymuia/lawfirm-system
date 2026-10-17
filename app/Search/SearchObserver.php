<?php

namespace App\Search;

use App\Models\Attorney;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ENG-19: what changes in which index when a record changes. A matter's people or client decide who may see its
 * documents, so changing them re-indexes the documents too; a client's name is part of its matters' records.
 */
class SearchObserver
{
    public static function register(): void
    {
        foreach ([Cases::class, Client::class, Document::class, Attorney::class] as $model) {
            foreach (['saved', 'deleted', 'restored'] as $event) {
                $model::$event(fn (Model $m) => self::changed($m));
            }
        }
    }

    public static function changed(Model $m): void
    {
        if (! SearchIndex::enabled()) {
            return;
        }
        if ($m instanceof Cases) {
            self::matter((int) $m->getKey(), ! $m->wasRecentlyCreated && $m->wasChanged(['assigned_to', 'created_by', 'client_id', 'case_number', 'deleted_at']));
        } elseif ($m instanceof Attorney) {
            self::matter((int) $m->case_id, true);
        } elseif ($m instanceof Client) {
            SearchIndex::queue('clients', [(int) $m->getKey()]);
            SearchIndex::queue('cases', DB::table('cases')->where('client_id', $m->getKey())->pluck('id')->all());
        } elseif ($m instanceof Document) {
            SearchIndex::queue('documents', [(int) $m->getKey()]);
        }
    }

    private static function matter(int $caseId, bool $withDocuments): void
    {
        SearchIndex::queue('cases', [$caseId]);
        if ($withDocuments) {
            DB::table('documents')->where('case_id', $caseId)->orderBy('id')->chunk(500, fn ($rows) => SearchIndex::queue('documents', $rows->pluck('id')->all()));
        }
    }
}
