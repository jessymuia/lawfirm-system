<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\IndexRequest;
use App\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SearchController extends Controller
{
    /**
     * FUN-7: global search across cases, clients and documents. Each kind is only searched (and only returned) if the
     * current user holds its LIST permission, among the records they may see; ENG-19: ranked by a search engine with
     * per-firm indexes when one is configured (App\Search\GlobalSearch, docs/SEARCH.md).
     */
    public function index(IndexRequest $request, GlobalSearch $search): JsonResponse
    {
        $results = $search->search(Auth::user(), (string) $request->input('q'));
        unset($results['engine']);

        return $this->response(true, 'success', $results, 200);
    }
}
