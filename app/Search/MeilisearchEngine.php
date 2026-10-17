<?php

namespace App\Search;

use App\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ENG-19: Meilisearch over its HTTP API. Every firm has its own indexes (`<prefix><firm>_<entity>`), so one firm's
 * records are never in another firm's results, nor in its ranking statistics; offboarding drops them whole.
 */
class MeilisearchEngine implements SearchEngine
{
    /** Per entity: what is searched (in order of weight), what can be filtered on, what is shown cropped. */
    public const SETTINGS = [
        'cases' => ['searchableAttributes' => ['case_number', 'client', 'opposing_party', 'description'], 'filterableAttributes' => ['id', 'client_id', 'access_users']],
        'clients' => ['searchableAttributes' => ['name', 'phone_number'], 'filterableAttributes' => ['id']],
        'documents' => ['searchableAttributes' => ['title', 'filename', 'case_number', 'content'], 'filterableAttributes' => ['case_id', 'client_id', 'access_users']],
    ];

    public static function index(string $entity, ?string $firm = null): string
    {
        return config('search.index_prefix').($firm ?? app(TenantContext::class)->key()).'_'.$entity;
    }

    public function search(string $entity, string $q, SearchFilter $filter, int $limit): array
    {
        $body = ['q' => $q, 'limit' => $limit, 'attributesToRetrieve' => ['id'], 'filter' => $filter->toMeilisearch()];
        if ($entity === 'documents') {
            $body += ['attributesToCrop' => ['content'], 'cropLength' => 24];
        }
        $r = $this->http()->post('/indexes/'.self::index($entity).'/search', array_filter($body, fn ($v) => $v !== null));
        if ($r->status() === 404) {
            return [];   // not indexed yet
        }
        $this->ok($r);

        return array_map(fn ($h) => ['id' => (int) $h['id'], 'snippet' => isset($h['_formatted']['content']) && $h['_formatted']['content'] !== '' ? (string) $h['_formatted']['content'] : null],
            (array) $r->json('hits'));
    }

    public function upsert(string $entity, array $records): void
    {
        if ($records) {
            $this->ok($this->http()->post('/indexes/'.self::index($entity).'/documents?primaryKey=id', $records));
        }
    }

    public function delete(string $entity, array $ids): void
    {
        if ($ids) {
            $r = $this->http()->post('/indexes/'.self::index($entity).'/documents/delete-batch', $ids);
            $r->status() === 404 || $this->ok($r);
        }
    }

    public function reset(string $entity): void
    {
        $r = $this->http()->delete('/indexes/'.self::index($entity).'/documents');
        $r->status() === 404 || $this->ok($r);
        $this->ok($this->http()->patch('/indexes/'.self::index($entity).'/settings', self::SETTINGS[$entity]));
    }

    public function dropFirm(string $firmKey): void
    {
        foreach (array_keys(self::SETTINGS) as $entity) {
            $r = $this->http()->delete('/indexes/'.self::index($entity, $firmKey));
            $r->status() === 404 || $this->ok($r);
        }
    }

    private function http(): PendingRequest
    {
        $key = config('search.meilisearch.key');

        return Http::baseUrl(rtrim((string) config('search.meilisearch.host'), '/'))->acceptJson()->asJson()
            ->timeout((int) config('search.meilisearch.timeout', 5))->when($key, fn ($h) => $h->withToken((string) $key));
    }

    private function ok(Response $r): void
    {
        if (! $r->successful()) {
            throw new RuntimeException('Search engine error '.$r->status().': '.($r->json('message') ?? mb_substr($r->body(), 0, 200)));
        }
    }
}
