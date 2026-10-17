<?php

namespace App\Http\Controllers;

use App\Http\Requests\MatterParty\StoreRequest;
use App\Models\Cases;
use App\Models\MatterParty;
use Illuminate\Http\JsonResponse;

/**
 * FUN-9: the people a matter is against or about. Reached through the matter, so matter access and ethical walls apply
 * (a matter the person cannot open is a 404, as everywhere else). The party mirrored from the matter's "opposing party"
 * field is changed there, not here.
 */
class MatterPartyController extends Controller
{
    public function index(int $case): JsonResponse
    {
        $matter = Cases::findOrFail($case);

        return $this->response(true, 'success', MatterParty::where('case_id', $matter->id)->orderBy('role')->orderBy('name')
            ->get(['id', 'case_id', 'role', 'name', 'identifier', 'notes', 'from_opposing_party', 'created_at']), 200);
    }

    public function store(StoreRequest $r, int $case): JsonResponse
    {
        $matter = Cases::findOrFail($case);
        $party = MatterParty::create(['case_id' => $matter->id] + $r->validated());

        return $this->response(true, 'success', $party->only(['id', 'case_id', 'role', 'name', 'identifier', 'notes', 'from_opposing_party']), 201);
    }

    public function update(StoreRequest $r, int $case, int $party): JsonResponse
    {
        $matter = Cases::findOrFail($case);
        $p = MatterParty::where('case_id', $matter->id)->findOrFail($party);
        if ($p->from_opposing_party) {
            return $this->response(false, 'This party comes from the matter\'s "opposing party" field; change it there.', null, 422);
        }
        $p->update($r->validated());

        return $this->response(true, 'success', $p->only(['id', 'case_id', 'role', 'name', 'identifier', 'notes', 'from_opposing_party']), 200);
    }

    public function destroy(int $case, int $party): JsonResponse
    {
        $matter = Cases::findOrFail($case);
        $p = MatterParty::where('case_id', $matter->id)->findOrFail($party);
        if ($p->from_opposing_party) {
            return $this->response(false, 'This party comes from the matter\'s "opposing party" field; change it there.', null, 422);
        }
        $p->deleted_by = auth()->id();
        $p->save();
        $p->delete();

        return $this->response(true, 'success', null, 200);
    }
}
