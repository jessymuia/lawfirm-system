<?php

namespace App\Http\Middleware;

use App\Models\Document;
use App\Models\DocumentAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * A refused document preview must itself be on the document's access log, not just rejected. That used to happen
 * inside CasesController::preview(); since ENG-11 the permission is checked at the route, before the controller, so
 * this middleware sits in front of `authz` on the preview route and records the 403 on its way out.
 */
class RecordDocumentAccessDenial
{
    public function handle(Request $request, Closure $next)
    {
        // The routing pipeline turns the authz abort(403) into a response before it reaches us, so look at the status.
        $response = $next($request);

        if ($response->getStatusCode() === 403 && $request->user() && ($doc = Document::find($request->query('id')))) {
            DocumentAccess::create([
                'document_id' => $doc->id,
                'accessed_by' => $request->user()->id,
                'accessed_date' => now(),
                'action' => 'preview',
                'ip_address' => $request->ip(),
                'outcome' => 'Unsuccessfull',
                'device_info' => 'device',
            ]);
        }

        return $response;
    }
}
