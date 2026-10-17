<?php

namespace App\Http\Middleware;

use App\Matters\MatterAccess;
use App\Matters\WallAttempts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-11: "attempted access is logged". Runs after the response (terminable), so it sees the outcome whatever the controller did
 * (404 from route binding, an empty filtered list, a refused write). For a screened user it looks at the ids in the URL and in
 * case_id/client_id/invoice_id inputs and, if any belongs to walled material, records one attempt for the request.
 * Users with no active screen cost nothing beyond the registry lookup the scopes already need.
 */
class LogWallAttempts
{
    /** path segment => [table, how it reaches a case] */
    private const TABLES = [
        'cases' => ['cases', 'self'], 'clients' => ['clients', 'client'],
        'hearings' => ['hearings', 'case_id'], 'documents' => ['documents', 'case_id'], 'expenses' => ['expenses', 'case_id'],
        'time-entries' => ['time_entries', 'case_id'], 'trust-transactions' => ['trust_transactions', 'case_id'],
        'invoices' => ['invoices', 'case_id'], 'preview-invoice' => ['invoices', 'case_id'], 'send-to-admin' => ['invoices', 'case_id'],
        'payments' => ['payments', 'invoice'], 'invoiceItems' => ['invoice_items', 'invoice'],
    ];

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('api/platform/*')) {
            return;   // platform staff are not tenant users
        }
        $user = Auth::user();
        if (! $user || ! MatterAccess::walls()->any($user->getKey()) || ! $request->is('api/*')) {
            return;
        }

        [$caseIds, $clientIds] = $this->targets($request);
        foreach ($caseIds as $caseId) {
            if ($this->log($request, $response, $user, $caseId, null)) {
                return;   // one attempt per request is enough evidence
            }
        }
        foreach ($clientIds as $clientId) {
            if ($this->log($request, $response, $user, null, $clientId)) {
                return;
            }
        }
    }

    private function log(Request $request, Response $response, $user, ?int $caseId, ?int $clientId): bool
    {
        return WallAttempts::record($user, 'api', $caseId, $clientId, [
            'method' => $request->method(), 'path' => $request->path(), 'status' => $response->getStatusCode(), 'ip' => $request->ip(),
        ]) !== null;
    }

    /** @return array{0: list<int>, 1: list<int>} case ids and client ids this request points at */
    private function targets(Request $request): array
    {
        $cases = $clients = [];

        $route = $request->route();
        $segments = array_values(array_filter(explode('/', $request->path()), fn ($s) => ! in_array($s, ['api', 'v1', 'export'], true) && ! is_numeric($s)));
        $spec = null;
        foreach ($segments as $seg) {
            if (isset(self::TABLES[$seg])) {
                $spec = self::TABLES[$seg];
                break;
            }
        }
        if ($spec && $route) {
            foreach ($route->parameters() as $name => $value) {
                $value = is_object($value) ? $value->getKey() : $value;
                if ($name === 'groupId') {
                    $caseId = DB::table('documents')->where('document_group_id', $value)->value('case_id');
                    $caseId && $cases[] = (int) $caseId;

                    continue;
                }
                if (! is_numeric($value)) {
                    continue;
                }
                [$table, $via] = $spec;
                $row = match ($via) {
                    'self' => (object) ['case_id' => (int) $value],
                    'client' => (object) ['client_id' => (int) $value],
                    'invoice' => ($inv = DB::table($table)->where('id', $value)->value('invoice_id')) ? DB::table('invoices')->where('id', $inv)->first(['case_id']) : null,
                    default => DB::table($table)->where('id', $value)->first(['case_id']),
                };
                if ($row && ! empty($row->case_id)) {
                    $cases[] = (int) $row->case_id;
                }
                if ($row && ! empty($row->client_id)) {
                    $clients[] = (int) $row->client_id;
                }
            }
        }

        foreach (['case_id', 'client_id', 'invoice_id'] as $key) {
            $v = $request->input($key);
            if (! is_numeric($v)) {
                continue;
            }
            if ($key === 'case_id') {
                $cases[] = (int) $v;
            } elseif ($key === 'client_id') {
                $clients[] = (int) $v;
            } elseif ($c = DB::table('invoices')->where('id', $v)->value('case_id')) {
                $cases[] = (int) $c;
            }
        }

        return [array_values(array_unique($cases)), array_values(array_unique($clients))];
    }
}
