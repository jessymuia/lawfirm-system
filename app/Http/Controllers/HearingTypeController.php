<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\HearingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HearingTypeController extends Controller
{
    private function response(bool $success, string $message,$data , int $statusCode):JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message'=>$message,
            'data' => $data
        ], $statusCode);
    }
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_CASE_DOCUMENTS))
            abort(403);
        $types = HearingType::all();
        $types = $types->map(function ($row) {
            return [
                "id" => $row->id,
                "name" => $row->name
            ];
        });

        return $this->response(true,'success',$types,200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        HearingType::create([
            'name' => $request->name,
            'created_by' => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\HearingType  $hearingType
     * @return \Illuminate\Http\Response
     */
    public function show(HearingType $hearingType)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\HearingType  $hearingType
     * @return \Illuminate\Http\Response
     */
    public function edit(HearingType $hearingType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\HearingType  $hearingType
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $type = HearingType::find($id);
        $type->name = $request->name;
        $type->save();

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\HearingType  $hearingType
     * @return \Illuminate\Http\Response
     */
    public function destroy(HearingType $hearingType)
    {
        //
    }
}
