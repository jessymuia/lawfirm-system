<?php

namespace App\Http\Controllers;

use App\Conflicts\ConflictChecks;
use App\Conflicts\ConflictException;
use App\Constants\ModulePermissions as P;
use App\Security\SecurityPolicy;
use App\Support\FirmSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FUN-9: conflict checks. Anyone who takes on clients or matters runs a check (run-conflict-checks); deciding to
 * proceed past a possible conflict, reading the firm's register and setting the policy need manage-conflicts.
 */
class ConflictController extends Controller
{
    public function __construct(private ConflictChecks $checks) {}

    public function check(\App\Http\Requests\Conflict\CheckRequest $r): JsonResponse
    {
        $d = $r->validated();
        $c = $this->checks->run($d['purpose'], array_values($d['names']), $d['phone'] ?? null, $d['identifier'] ?? null, Auth::user(), $d['exclude_case_id'] ?? null);

        return $this->response(true, $c->outcome === 'clear' ? 'No conflicts found.' : 'Possible conflicts found.', $this->view($c), 200);
    }

    public function show(int $id): JsonResponse
    {
        $c = $this->checks->find($id) ?? abort(404);
        // your own checks, or any check for a conflicts administrator
        abort_unless((int) $c->created_by === (int) Auth::id() || Auth::user()->checkPermissionTo(P::MANAGE_CONFLICTS), 404);

        return $this->response(true, 'success', $this->view($c), 200);
    }

    public function decide(\App\Http\Requests\Conflict\DecideRequest $r, int $id): JsonResponse
    {
        $d = $r->validated();
        try {
            $c = $this->checks->decide($id, $d['decision'], $d['note'], Auth::user());
        } catch (ConflictException $e) {
            return $this->response(false, $e->getMessage(), null, $e->status);
        }
        SecurityPolicy::audit('conflict_'.$d['decision'], 'conflict_check', $id, [], ['decision' => $d['decision'], 'note' => $d['note'], 'outcome' => $c->outcome]);

        return $this->response(true, $d['decision'] === 'proceed' ? 'Decision recorded: the intake may proceed.' : 'Decision recorded: the intake was declined.', $this->view($c), 200);
    }

    /** The register: every check, newest first; ?outcome=potential&undecided=1 for the ones waiting on a decision. */
    public function index(Request $r): JsonResponse
    {
        $q = DB::table('conflict_checks')
            ->leftJoin('users as u', 'u.id', '=', 'conflict_checks.created_by')
            ->leftJoin('users as d', 'd.id', '=', 'conflict_checks.decided_by')
            ->select('conflict_checks.*', 'u.name as created_by_name', 'd.name as decided_by_name')
            ->when($r->query('outcome'), fn ($q, $o) => $q->where('outcome', $o))
            ->when($r->boolean('undecided'), fn ($q) => $q->where('outcome', 'potential')->whereNull('decision'))
            ->orderByDesc('conflict_checks.id');
        $page = $q->paginate(min(100, max(1, (int) $r->query('per_page', 25))));
        $page->getCollection()->transform(function ($c) {
            $c->query = json_decode($c->query, true);
            $c->hits = json_decode($c->hits, true);

            return $this->view($c) + ['created_by_name' => $c->created_by_name, 'decided_by_name' => $c->decided_by_name];
        });

        return $this->response(true, 'success', $page, 200);
    }

    public function policy(): JsonResponse
    {
        return $this->response(true, 'success', ['required' => ConflictChecks::required()], 200);
    }

    public function updatePolicy(\App\Http\Requests\Conflict\PolicyRequest $r): JsonResponse
    {
        $before = ConflictChecks::required();
        FirmSettings::set('conflicts.required', (bool) $r->validated('required'), Auth::id());
        SecurityPolicy::audit('conflict_policy_updated', 'conflict_policy', 0, ['required' => $before], ['required' => ConflictChecks::required()]);

        return $this->response(true, 'success', ['required' => ConflictChecks::required()], 200);
    }

    private function view(object $c): array
    {
        return [
            'id' => $c->id, 'purpose' => $c->purpose, 'outcome' => $c->outcome, 'query' => $c->query, 'hits' => $c->hits,
            'decision' => $c->decision, 'decision_note' => $c->decision_note, 'decided_at' => $c->decided_at,
            'subject_type' => $c->subject_type, 'subject_id' => $c->subject_id, 'created_at' => $c->created_at,
            'usable' => ! $c->subject_id && ($c->outcome === 'clear' || $c->decision === 'proceed'),
        ];
    }
}
