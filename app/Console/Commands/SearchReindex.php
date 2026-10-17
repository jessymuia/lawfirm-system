<?php

namespace App\Console\Commands;

use App\Search\SearchIndex;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** ENG-19: (re)build the search indexes from the database: after enabling the engine, or to repair them. */
class SearchReindex extends Command
{
    protected $signature = 'search:reindex {--entity=* : cases, clients, documents (default: all)} {--tenant= : one firm (multi-tenant)}';

    protected $description = 'Rebuild the search engine indexes of this firm (or every firm)';

    public function handle(TenantManager $tenants, SearchIndex $index): int
    {
        if (! SearchIndex::enabled()) {
            $this->warn('SEARCH_ENGINE is "database": there is nothing to index.');

            return self::SUCCESS;
        }
        $entities = $this->option('entity') ?: SearchIndex::ENTITIES;
        if ($bad = array_diff($entities, SearchIndex::ENTITIES)) {
            $this->error('Unknown entity: '.implode(', ', $bad));

            return self::FAILURE;
        }
        $run = function (string $label) use ($index, $entities) {
            foreach ($entities as $entity) {
                $this->line("{$label}: {$entity} ".$index->rebuild($entity));
            }
        };

        if (! $tenants->isMulti()) {
            $run('this firm');

            return self::SUCCESS;
        }
        $firms = Tenant::whereIn('status', ['active', 'suspended'])->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))->get();
        foreach ($firms as $tenant) {
            $tenants->runIn($tenant, fn () => $run($tenant->slug), allowInactive: true);
        }

        return self::SUCCESS;
    }
}
