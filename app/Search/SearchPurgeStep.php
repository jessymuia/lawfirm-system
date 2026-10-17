<?php

namespace App\Search;

use App\Tenancy\Models\Tenant;
use App\Tenancy\PurgeStep;

/** ENG-19 / TEN-9: deleting a firm deletes its search indexes (they hold copies of its records). */
class SearchPurgeStep implements PurgeStep
{
    public function name(): string
    {
        return 'search_indexes';
    }

    public function purge(Tenant $tenant): string
    {
        if (! SearchIndex::enabled()) {
            return 'none: no search engine configured';
        }
        app(SearchEngine::class)->dropFirm($tenant->slug);

        return 'dropped';
    }
}
