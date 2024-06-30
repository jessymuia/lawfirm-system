<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Court;
use App\Models\Hearing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HearingController extends Controller
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
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_CASE_DOCUMENTS))
            abort(403);
        $hearings = Hearing::orderBy("id","desc")->get();
        $hearings = $hearings->map(function ($row) {
           return [
               "id" => $row->id,
               "case_id" => $row->case_id,
               "case" => Cases::find($row->case_id)->case_number,
               "court_id" => $row->court_id,
               "court" => Court::find($row->court_id)->name,
               "hearing_date" => Carbon::parse($row->hearing_date)->format('d/m/Y'),
               "hearing_type" => $row->hearing_type,
               "hearing_type_name" => $row->hearingTypeName->name,
               "notes" => $row->notes,
               "outcome" => $row->outcome
           ];
        });

        return $this->response(true,'success',$hearings, 200);
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
        Hearing::create([
            "case_id" => $request->case_id,
            "court_id" => $request->court_id,
            "hearing_date" => Carbon::createFromFormat('d/m/Y', $request->hearing_date)->format('Y-m-d'),
            "hearing_type" => $request->hearing_type,
            "notes" => $request->notes,
            "outcome" => $request->outcome,
            "created_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Hearing  $hearing
     * @return \Illuminate\Http\Response
     */
    public function show(Hearing $hearing)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Hearing  $hearing
     * @return \Illuminate\Http\Response
     */
    public function edit(Hearing $hearing)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Hearing  $hearing
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $hearing = Hearing::find($id);
        $hearing->update([
            "case_id" => $request->case_id,
            "court_id" => $request->court_id,
            "hearing_date" => Carbon::createFromFormat('d/m/Y', $request->hearing_date)->format('Y-m-d'),
            "hearing_type" => $request->hearing_type,
            "notes" => $request->notes,
            "outcome" => $request->outcome,
            "updated_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Hearing  $hearing
     * @return \Illuminate\Http\Response
     */
    public function destroy(Hearing $hearing)
    {
        //
    }
}
