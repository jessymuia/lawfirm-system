<?php

namespace App\Search;

/**
 * ENG-19: no search engine. Global search runs as SQL (GlobalSearch::database()); nothing is indexed, so every
 * write here is a no-op and the engine is never asked to search.
 */
class DatabaseEngine implements SearchEngine
{
    public function search(string $entity, string $q, SearchFilter $filter, int $limit): array
    {
        return [];
    }

    public function upsert(string $entity, array $records): void {}

    public function delete(string $entity, array $ids): void {}

    public function reset(string $entity): void {}

    public function dropFirm(string $firmKey): void {}
}
