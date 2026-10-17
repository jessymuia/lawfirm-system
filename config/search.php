<?php

/*
 * ENG-19: global search (docs/SEARCH.md).
 *   database     LIKE over titles and names, plus the documents' full-text (FUN-8). No extra service; fine for a firm.
 *   meilisearch  a search engine with one set of indexes per firm, ranked by relevance and typo-tolerant, with
 *                permissions applied by the engine at query time. Fill the indexes with `php artisan search:reindex`.
 */
return [
    'engine' => env('SEARCH_ENGINE', 'database'),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'timeout' => (int) env('MEILISEARCH_TIMEOUT', 5),
    ],

    // Index names are <prefix><firm>_<entity>; a prefix lets several deployments share one search cluster.
    'index_prefix' => env('SEARCH_INDEX_PREFIX', ''),

    // How much of a document's extracted text (FUN-8) goes into the index.
    'document_text_chars' => (int) env('SEARCH_DOCUMENT_TEXT_CHARS', 20000),
];
