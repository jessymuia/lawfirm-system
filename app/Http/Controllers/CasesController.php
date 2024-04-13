<?php

namespace App\Http\Controllers;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasesController extends Controller
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
        $cases = Cases::all();
        $cases = $cases->map(function ($row) {
           return [
               "id" => $row->id,
               "case_number" => $row->case_number,
               "description" => $row->description,
               "client_id" => $row->client_id,
               "client" => Client::find($row->client_id)->name,
               "assigned_to" => $row->assigned_to,
               "attorney" => User::find($row->assigned_to)->name ?? null,
               "start_date" => Carbon::parse($row->start_date)->format('d/m/Y'),
               "end_date" => Carbon::parse($row->end_date)->format('d/m/Y'),
               "case_type" => $row->case_type,
               "police_station" => $row->police_station,
               "court_id" => $row->court_id,
               "court" => Court::find($row->court_id)->name,
               "opposing_party" => $row->opposing_party

           ];
        });

        return $this->response(true,'success',$cases,200);
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
        Cases::create([
            "case_number" => $request->case_number,
            "description" => $request->description,
            "client_id" => $request->client_id,
            "assigned_to" => $request->assigned_to,
            "start_date" => Carbon::createFromFormat('d/m/Y', $request->start_date)->format('Y-m-d'),
            "end_date" => Carbon::createFromFormat('d/m/Y', $request->end_date)->format('Y-m-d'),
            "police_station" => $request->police_station,
            "court_id" => $request->court_id,
            "opposing_party" => $request->opposing_party,
            "case_type" => $request->case_type
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Cases  $cases
     * @return \Illuminate\Http\Response
     */
    public function show(Cases $cases)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Cases  $cases
     * @return \Illuminate\Http\Response
     */
    public function edit(Cases $cases)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Cases  $cases
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $case = Cases::find($id);
        $case->update([
            "case_number" => $request->case_number,
            "description" => $request->description,
            "client_id" => $request->client_id,
            "assigned_to" => $request->assigned_to,
            "start_date" => Carbon::createFromFormat('d/m/Y', $request->start_date)->format('Y-m-d'),
            "end_date" => Carbon::createFromFormat('d/m/Y', $request->end_date)->format('Y-m-d'),
            "police_station" => $request->police_station,
            "court_id" => $request->court_id,
            "opposing_party" => $request->opposing_party,
            "case_type" => $request->case_type
        ]);

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Cases  $cases
     * @return \Illuminate\Http\Response
     */
    public function destroy(Cases $cases)
    {
        //
    }

    public function dropDown(): JsonResponse
    {
        $cases = Cases::all();
        $cases = $cases->map(function ($row) {
           return [
               "id" => $row->id,
               "name" => "Case Number => ".$row->case_number
           ];
        });

        return $this->response(true,'success', $cases,200);
    }
}
