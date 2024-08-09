<?php

namespace App\Http\Controllers;

use App\Models\Attorney;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AttorneyController extends Controller
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
    public function index()
    {
        //
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
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Attorney  $attorney
     * @return \Illuminate\Http\Response
     */
    public function show(Attorney $attorney)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Attorney  $attorney
     * @return \Illuminate\Http\Response
     */
    public function edit(Attorney $attorney)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Attorney  $attorney
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Attorney $attorney)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Attorney  $attorney
     * @return \Illuminate\Http\Response
     */
    public function destroy(Attorney $attorney)
    {
        //
    }

    public function getAdvocateUsers(): JsonResponse
    {
        $advocateUsers = User::whereHas('roles', function ($query) {
            $query->where('name', 'advocates')
                ->orWhere('name', 'ADVOCATES')->orWhere('name','advocate');
        })->get();


        $advocateUsers = $advocateUsers->map(function ($row) {
            return [
                "id" => $row->id,
                "name" => $row->name
            ];
        });

        return $this->response(true,'success',$advocateUsers,200);
    }
}
