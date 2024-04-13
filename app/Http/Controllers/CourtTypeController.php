<?php

namespace App\Http\Controllers;

use App\Models\CourtType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtTypeController extends Controller
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
        $types = CourtType::all();
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
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        CourtType::create([
            'name' => $request->name
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\CourtType  $courtType
     * @return \Illuminate\Http\Response
     */
    public function show(CourtType $courtType)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\CourtType  $courtType
     * @return \Illuminate\Http\Response
     */
    public function edit(CourtType $courtType)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\CourtType  $courtType
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $type = CourtType::find($id);
        $type->name = $request->name;
        $type->save();

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CourtType  $courtType
     * @return \Illuminate\Http\Response
     */
    public function destroy(CourtType $courtType)
    {
        //
    }
}
