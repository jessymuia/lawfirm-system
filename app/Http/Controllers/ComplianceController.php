<?php

namespace App\Http\Controllers;

use App\Compliance\Dsar;
use App\Compliance\LegalHolds;
use App\Compliance\Retention;
use App\Http\Requests\Compliance\CreateDsarRequest;
use App\Http\Requests\Compliance\EraseDsarRequest;
use App\Http\Requests\Compliance\PlaceHoldRequest;
use App\Http\Requests\Compliance\RejectDsarRequest;
use App\Http\Requests\Compliance\ReleaseHoldRequest;
use App\Http\Requests\Compliance\SetRetentionRequest;
use App\Http\Requests\Compliance\VerifyDsarRequest;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** SEC-13: legal holds, retention and data-subject requests. All routes need `manage-compliance`. */
class ComplianceController extends Controller
{
    public function __construct(private LegalHolds $holds, private Retention $retention, private Dsar $dsar) {}

    // ---- legal holds ---------------------------------------------------------------------------------------------

    public function holds(Request $r)
    {
        $q = DB::table('legal_holds')->orderByDesc('id');
        if ($r->boolean('active')) {
            $q->whereNull('released_at');
        }
        $rows = $q->limit(500)->get();
        // what each hold is on, by name, for the Compliance screen (SEC-13)
        $clients = DB::table('clients')->whereIn('id', $rows->where('scope_type', 'client')->pluck('scope_id'))->pluck('name', 'id');
        $cases = DB::table('cases')->whereIn('id', $rows->where('scope_type', 'case')->pluck('scope_id'))->pluck('case_number', 'id');

        return response()->json(['data' => $rows->map(fn ($h) => (array) $h + ['scope_label' => $h->scope_type === 'client'
            ? ($clients[$h->scope_id] ?? "Client #{$h->scope_id}") : ($cases[$h->scope_id] ?? "Matter #{$h->scope_id}")])->values()]);
    }

    public function placeHold(PlaceHoldRequest $r)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->holds->place($d['scope_type'], (int) $d['scope_id'], $d['reason'], $r->user()->id)], 201);
    }

    public function releaseHold(ReleaseHoldRequest $r, int $id)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->holds->release($id, $d['reason'], $r->user()->id)]);
    }

    // ---- retention -----------------------------------------------------------------------------------------------

    public function retention()
    {
        return response()->json(['data' => ['classes' => $this->retention->policies(), 'minimum_years' => (int) config('compliance.minimum_retention_years')]]);
    }

    public function setRetention(SetRetentionRequest $r, string $class)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->retention->set($class, (int) $d['retain_years'], (bool) ($d['auto_purge'] ?? false), $r->user()->id)]);
    }

    /** What the next purge would dispose of, for one class (?class=, documents by default). Counts and ids only; nothing is changed. */
    public function retentionDue(Request $r)
    {
        $class = (string) $r->query('class', Retention::CLASS_DOCS);
        if (! in_array($class, $this->retention->purgeableClasses(), true)) {
            return response()->json(['message' => 'Unknown or non-purgeable record class.'], 422);
        }
        $x = $this->retention->due($class);
        $ids = $x['due']->take(500)->values();

        return response()->json(['data' => ['record_class' => $class, 'cutoff' => $x['cutoff'], 'due_count' => $x['due']->count(), 'held_count' => $x['held'],
            'ids' => $ids] + ($class === Retention::CLASS_DOCS ? ['document_ids' => $ids] : [])]);
    }

    // ---- data-subject requests -----------------------------------------------------------------------------------

    public function dsars()
    {
        $visible = Client::query()->select('id');

        return response()->json(['data' => DB::table('dsar_requests')->leftJoin('clients', 'clients.id', '=', 'dsar_requests.client_id')
            ->whereIn('dsar_requests.client_id', $visible)->orderByDesc('dsar_requests.id')->limit(500)
            ->get(['dsar_requests.*', 'clients.name as client_name'])]);
    }

    public function createDsar(CreateDsarRequest $r)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->dsar->create($d['type'], (int) $d['client_id'], $d['requester_name'], $d['requester_email'] ?? null, $d['received_at'] ?? null, $r->user()->id)], 201);
    }

    public function showDsar(int $id)
    {
        return response()->json(['data' => $this->dsar->find($id)]);
    }

    public function verifyDsar(VerifyDsarRequest $r, int $id)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->dsar->verify($id, $d['method'], $d['note'], $r->user()->id)]);
    }

    public function rejectDsar(RejectDsarRequest $r, int $id)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->dsar->reject($id, $d['reason'], $r->user()->id)]);
    }

    public function exportDsar(Request $r, int $id)
    {
        $this->dsar->export($id, $r->user()->id);

        return response($this->dsar->read($id), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="dsar-'.$id.'.json"']);
    }

    public function eraseDsar(EraseDsarRequest $r, int $id)
    {
        $res = $this->dsar->erase($id, $r->user()->id);

        return response()->json(['data' => $res], $res->status === 'blocked' ? 409 : 200);
    }
}
