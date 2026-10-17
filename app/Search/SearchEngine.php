<?php

namespace App\Search;

/** ENG-19: where indexed records live and are searched. Entities: cases, clients, documents. */
interface SearchEngine
{
    /**
     * Records matching $q that pass $filter, best first.
     *
     * @return list<array{id: int, snippet: ?string}>
     */
    public function search(string $entity, string $q, SearchFilter $filter, int $limit): array;

    /** @param list<array<string, mixed>> $records each with an integer `id` */
    public function upsert(string $entity, array $records): void;

    /** @param list<int> $ids */
    public function delete(string $entity, array $ids): void;

    /** Empty the firm's index of $entity and (re)apply its settings. */
    public function reset(string $entity): void;

    /** Remove every index of a firm (offboarding). */
    public function dropFirm(string $firmKey): void;
}
