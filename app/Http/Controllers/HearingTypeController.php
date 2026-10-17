<?php

namespace App\Http\Controllers;

use App\Catalog\HandlesCatalogRows;
use App\Constants\ModulePermissions;
use App\Http\Requests\HearingType\StoreRequest;
use App\Http\Requests\HearingType\UpdateRequest;
use App\Http\Resources\V1\HearingTypeResource;
use App\Models\HearingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HearingTypeController extends Controller
{
    use HandlesCatalogRows;

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_HEARINGS)) {
            abort(403);
        }

        $query = HearingType::orderBy('id', 'desc');
        $types = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => HearingTypeResource::make($row)->resolve(),
            25,
            ['name'],
            ['name', 'alias']
        );

        return $this->response(true, 'success', $types, 200);
    }

    /** ENG-16: dropdown options (id + name), capped; see Controller::optionsResponse(). */
    public function options(Request $request): JsonResponse
    {
        return $this->optionsResponse($request, HearingType::query(), fn ($r) => $r->displayName());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRequest $request): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_HEARINGS)) {
            abort(403);
        }

        $type = HearingType::create([
            'name' => $request->name,
        ]);

        return $this->response(true, 'success', HearingTypeResource::make($type)->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRequest $request, $id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_HEARINGS)) {
            abort(403);
        }

        $type = HearingType::findOrFail($id);
        if ($type->is_system) {
            // TEN-11: a firm can only give a system row a local name.
            return $this->response(true, 'success', HearingTypeResource::make($this->updateCatalogAlias($request, $type))->resolve(), 200);
        }

        $type->name = $request->name;
        $type->updated_by = auth()->user()->id;
        $type->save();

        return $this->response(true, 'success', HearingTypeResource::make($type)->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_HEARINGS)) {
            abort(403);
        }

        $type = HearingType::findOrFail($id);
        $this->refuseSystemDelete($type);
        $type->deleted_by = auth()->user()->id;
        $type->save();

        $type->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }
}
