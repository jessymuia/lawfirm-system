<?php

namespace App\Http\Controllers;

use App\Catalog\HandlesCatalogRows;
use App\Constants\ModulePermissions;
use App\Models\Court;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CourtController extends Controller
{
    use HandlesCatalogRows;

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_COURTS)) {
            abort(403);
        }

        $query = Court::with('courtType')->orderBy('id', 'desc');

        $courts = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => \App\Http\Resources\V1\CourtResource::make($row)->resolve(),
            25,
            sortableColumns: ['name'],
            searchableColumns: ['name', 'alias']
        );

        return $this->response(true, 'success', $courts, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(\App\Http\Requests\Court\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_COURTS)) {
            abort(403);
        }

        $court = Court::create([
            'name' => $request->name,
            'type' => $request->type,
        ]);

        return $this->response(true, 'success', \App\Http\Resources\V1\CourtResource::make($court->load('courtType'))->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(\App\Http\Requests\Court\UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_COURTS)) {
            abort(403);
        }

        $court = Court::findOrFail($id);
        if ($court->is_system) {
            // TEN-11: a firm can only give a system row a local name.
            return $this->response(true, 'success', \App\Http\Resources\V1\CourtResource::make($this->updateCatalogAlias($request, $court)->load('courtType'))->resolve(), 200);
        }

        $court->name = $request->name;
        $court->type = $request->type;
        $court->updated_by = auth()->user()->id;
        $court->save();

        return $this->response(true, 'success', \App\Http\Resources\V1\CourtResource::make($court->fresh('courtType'))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_COURTS)) {
            abort(403);
        }

        $court = Court::findOrFail($id);
        $this->refuseSystemDelete($court);
        $court->deleted_by = auth()->user()->id;
        $court->save();

        $court->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }
}
