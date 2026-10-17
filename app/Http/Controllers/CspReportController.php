<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SEC-14: browsers post Content-Security-Policy violations here. Each is logged (a warning, with the firm and request
 * id from the log context) so the policy can be tuned before it is enforced. Unauthenticated by nature; throttled,
 * size-limited, and only the fields that describe the violation are kept (no page content).
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $raw = mb_substr((string) $request->getContent(), 0, 8192);
        $data = json_decode($raw, true);
        $r = is_array($data) ? (array) ($data['csp-report'] ?? ($data[0]['body'] ?? $data)) : [];
        $keep = ['document-uri', 'documentURL', 'violated-directive', 'effectiveDirective', 'effective-directive', 'blocked-uri', 'blockedURL', 'source-file', 'sourceFile', 'line-number', 'lineNumber', 'disposition'];
        $fields = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 300) : null, array_intersect_key($r, array_flip($keep)));
        if ($fields !== []) {
            Log::warning('Content-Security-Policy violation', $fields);
        }

        return response()->noContent();
    }
}
