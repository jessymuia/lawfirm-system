<?php

namespace App\Http\Controllers;

use App\Catalog\HandlesCatalogRows;
use App\Constants\ModulePermissions;
use App\Http\Requests\CourtType\StoreRequest;
use App\Http\Requests\CourtType\UpdateRequest;
use App\Http\Resources\V1\CourtTypeResource;
use App\Models\CourtType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CourtTypeController extends Controller
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

        $query = CourtType::orderBy('id', 'desc');
        $types = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => CourtTypeResource::make($row)->resolve(),
            25,
            ['name'],
            ['name', 'alias']
        );

        return $this->response(true, 'success', $types, 200);
    }

    /** ENG-16: dropdown options (id + name), capped; see Controller::optionsResponse(). */
    public function options(Request $request): JsonResponse
    {
        return $this->optionsResponse($request, CourtType::query(), fn ($r) => $r->displayName());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_COURTS)) {
            abort(403);
        }

        $type = CourtType::create([
            'name' => $request->name,
        ]);

        return $this->response(true, 'success', CourtTypeResource::make($type)->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_COURTS)) {
            abort(403);
        }

        $type = CourtType::findOrFail($id);
        if ($type->is_system) {
            // TEN-11: a firm can only give a system row a local name.
            return $this->response(true, 'success', CourtTypeResource::make($this->updateCatalogAlias($request, $type))->resolve(), 200);
        }

        $type->name = $request->name;
        $type->updated_by = auth()->user()->id;
        $type->save();

        return $this->response(true, 'success', CourtTypeResource::make($type)->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_COURTS)) {
            abort(403);
        }

        $type = CourtType::findOrFail($id);
        $this->refuseSystemDelete($type);
        $type->deleted_by = auth()->user()->id;
        $type->save();

        $type->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }
}
