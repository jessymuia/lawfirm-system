<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ExpenseCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index()
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_EXPENSES)) {
            abort(403);
        }

        $query = ExpenseCategory::orderBy('id', 'desc');
        $types = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => \App\Http\Resources\V1\ExpenseCategoryResource::make($row)->resolve(),
            25,
            ['name'],
            ['name']
        );

        return $this->response(true, 'success', $types, 200);
    }

    /** ENG-16: dropdown options (id + name), capped; see Controller::optionsResponse(). */
    public function options(\Illuminate\Http\Request $request): JsonResponse
    {
        return $this->optionsResponse($request, ExpenseCategory::query(), fn ($r) => $r->name);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return JsonResponse
     */
    public function store(\App\Http\Requests\ExpenseCategory\StoreRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_EXPENSES)) {
            abort(403);
        }

        $type = ExpenseCategory::create([
            'name' => $request->name,
        ]);

        return $this->response(true, 'success', \App\Http\Resources\V1\ExpenseCategoryResource::make($type)->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return JsonResponse
     */
    public function update(\App\Http\Requests\ExpenseCategory\UpdateRequest $request, $id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_EXPENSES)) {
            abort(403);
        }

        $type = ExpenseCategory::findOrFail($id);
        $type->name = $request->name;
        $type->updated_by = auth()->user()->id;
        $type->save();

        return $this->response(true, 'success', \App\Http\Resources\V1\ExpenseCategoryResource::make($type)->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_EXPENSES)) {
            abort(403);
        }

        $type = ExpenseCategory::findOrFail($id);
        $type->deleted_by = auth()->user()->id;
        $type->save();

        $type->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }
}
