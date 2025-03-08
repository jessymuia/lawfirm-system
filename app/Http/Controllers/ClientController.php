<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
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
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_CLIENTS))
            abort(403);
        $clients = Client::all();
        $clients = $clients->map(function ($row) {
           return [
               "id" => $row->id,
               "name" => $row->name,
               "phone_number" => $row->phone_number,
               "extra_phone_number" => $row->extra_phone_number,
               "address" => $row->address,
               "advocate" => User::find($row->advocate_id)->name,
               "advocate_id" => $row->advocate_id
           ];
        });
        return $this->response(true,'success', $clients,200);

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
        try {
            Client::create([
                'name' => $request->name,
                'phone_number' => $request->phone_number,
                'extra_phone_number' => $request->extra_phone_number ?? null,
                'address' => $request->address,
                'advocate_id' => $request->advocate,
                "created_by" => auth()->user()->id
            ]);

            sendCustomSMS($request->phone_number,"Welcome. You have been registered to the system");

            return $this->index();
        }catch (\Exception $exception) {
            Log::error($exception->getMessage().
                " ".$exception->getLine().
                " ".$exception->getFile().
                " ".$exception->getTraceAsString()
            );
            return $this->response(false,'An error occurred',[],500);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function show(Client $client)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function edit(Client $client)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $client = Client::findOrFail($id);
        $client->update([
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'extra_phone_number' => $request->extra_phone_number,
            'address' => $request->address,
            'advocate_id' => $request->advocate,
            "updated_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Client  $client
     * @return \Illuminate\Http\Response
     */
    public function destroy(Client $client)
    {
        //
    }

    public function clientsDropDown(): JsonResponse
    {
        $clients = Client::all();
        $clients = $clients->map(function ($row) {
           return [
               "id" => $row->id,
               "name" => $row->name
           ];
        });

        return $this->response(true,'success',$clients,200);
    }
}
