<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Court;
use App\Models\CourtType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CourtController extends Controller
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
     * @return \Illuminate\Http\Response
     */
    public function index(): \Illuminate\Http\Response|JsonResponse
    {
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_COURTS))
            abort(403);
        $courts = Court::all();
        $courts = $courts->map(function ($row) {
            return [
                "id" => $row->id,
                "name" => $row->name,
                "type" => $row->type,
                "court_type" => CourtType::find($row->type)->name
            ];
        });

        return $this->response(true,'success',$courts,200);
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
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        Court::create([
            "name" => $request->name,
            "type" => $request->type
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Court  $court
     * @return \Illuminate\Http\Response
     */
    public function show(Court $court)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Court  $court
     * @return \Illuminate\Http\Response
     */
    public function edit(Court $court)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Court  $court
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $court = Court::find($id);
        $court->name = $request->name;
        $court->type = $request->type;
        $court->updated_by = auth()->user()->id;
        $court->save();

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Court  $court
     * @return \Illuminate\Http\Response
     */
    public function destroy(Court $court)
    {
        //
    }
}
