<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\CourtType;
use App\Models\Document;
use App\Models\DocumentAccess;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_CASE_DOCUMENTS))
            abort(403);
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
               "start_date" => $row->start_date ? Carbon::parse($row->start_date)->format('d/m/Y') : null,
               "end_date" => $row->end_date ? Carbon::parse($row->end_date)->format('d/m/Y') : null,
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
        $request->validate([
            'case_number' => 'required|unique:cases',
        ],[
            'case_number.unique' => 'Case number already exists',
        ]);
        Cases::create([
            "case_number" => $request->case_number,
            "description" => $request->description,
            "client_id" => $request->client_id,
            "assigned_to" => $request->assigned_to,
            "start_date" => Carbon::createFromFormat('d/m/Y', $request->start_date)->format('Y-m-d'),
            "end_date" => $request->end_date ? Carbon::createFromFormat('d/m/Y', $request->end_date)->format('Y-m-d') : null,
            "police_station" => $request->police_station,
            "court_id" => $request->court_id,
            "opposing_party" => $request->opposing_party,
            "case_type" => $request->case_type,
            "created_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Cases  $cases
     * @return JsonResponse
     */
    public function show($id)
    {
        if(!$id) {
            abort(404);
        }

        $case = Cases::find($id);

        if(!$case) {
            return $this->response(false,'error',null, 404);
        }

        $data = [
            "id" => $case->id,
            "assigned" => $case->assigned_to ? User::find($case->assigned_to)->name : "Not assigned",
            "case_number" => $case->case_number,
            "client" => Client::find($case->client_id)->name ,
            "court" => $case->court_id ? Court::find($case->court_id)->name : "",
            "type" => $case->case_type,
            "police_station" => $case->police_station,
            "start_date" => $case->start_date,
            "opposing_party" => $case->opposing_party,
            "description" => $case->description
        ];

        return $this->response(true,'success' , $data, 200);
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
            "end_date" => $request->end_date ? Carbon::createFromFormat('d/m/Y', $request->end_date)->format('Y-m-d') : null,
            "police_station" => $request->police_station,
            "court_id" => $request->court_id,
            "opposing_party" => $request->opposing_party,
            "case_type" => $request->case_type,
            "updated_by" => auth()->user()->id
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

    public function preview()
    {
        $id = \request()->id;
        $doc = Document::findOrFail($id);

        $userAgent = \request()->header('User-Agent');
        //$browser = get_browser($userAgent,true);


        $docAccess = DocumentAccess::create([
            'document_id' => $doc->id,
            'accessed_by' => auth()->user()->id,
            'accessed_date' => now(),
            'action' => 'preview',
            'ip_address' => \request()->ip(),
            'outcome' => 'success',
            'device_info' => 'device'//$browser['device_type']
        ]);

        if(!Auth::user()->hasPermissionTo('create-cases')) {
            $docAccess->outcome = "Unsuccessfull";
            $docAccess->save();
            abort(403);
        }

        $storagePath = storage_path('app/');
        $full_path = $storagePath.$doc->full_path;
        return response()->file($full_path);
    }
}
