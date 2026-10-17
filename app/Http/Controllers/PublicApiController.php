<?php

namespace App\Http\Controllers;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Hearing;
use App\PublicApi\Resources;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** PLAT-11: read-only public API. Authorised by `api.key:<scope>` (see ApiKeyAuth); data is scoped to what the key's user may see. */
class PublicApiController extends Controller
{
    private function page(Request $r, $query, callable $map)
    {
        $per = max(1, min((int) $r->query('per_page', 25), (int) config('public_api.max_per_page')));
        if ($since = $r->query('updated_since')) {
            try {
                $query->where('updated_at', '>=', Carbon::parse((string) $since));
            } catch (\Throwable) {
                return response()->json(['success' => false, 'code' => 'bad_request', 'message' => 'updated_since must be an ISO-8601 timestamp.'], 422);
            }
        }
        $p = $query->orderBy('id')->paginate($per);

        return response()->json(['data' => $p->getCollection()->map($map)->values(), 'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]]);
    }

    public function clients(Request $r)
    {
        return $this->page($r, Client::query(), fn ($c) => Resources::client($c));
    }

    public function client(string $id)
    {
        abort_unless(Client::isPublicId($id), 404);

        return response()->json(['data' => Resources::client(Client::with([])->wherePublicId($id)->firstOrFail())]);
    }

    public function cases(Request $r)
    {
        return $this->page($r, Cases::with('client'), fn ($c) => Resources::matter($c));
    }

    public function case(string $id)
    {
        abort_unless(Cases::isPublicId($id), 404);

        return response()->json(['data' => Resources::matter(Cases::with(['client'])->wherePublicId($id)->firstOrFail())]);
    }

    public function hearings(Request $r)
    {
        return $this->page($r, Hearing::with(['case', 'hearingTypeName']), fn ($h) => Resources::hearing($h));
    }

    public function hearing(string $id)
    {
        abort_unless(Hearing::isPublicId($id), 404);

        return response()->json(['data' => Resources::hearing(Hearing::with(['case', 'hearingTypeName'])->wherePublicId($id)->firstOrFail())]);
    }
}
