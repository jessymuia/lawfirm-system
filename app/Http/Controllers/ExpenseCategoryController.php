<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
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
    public function index()
    {
        $types = ExpenseCategory::all();
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
    public function store(Request $request)
    {
        ExpenseCategory::create([
            'name' => $request->name,
            'created_by' => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return \Illuminate\Http\Response
     */
    public function show(ExpenseCategory $expenseCategory)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return \Illuminate\Http\Response
     */
    public function edit(ExpenseCategory $expenseCategory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        $type = ExpenseCategory::find($id);
        $type->name = $request->name;
        $type->updated_by = auth()->user()->id;
        $type->save();

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ExpenseCategory  $expenseCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy(ExpenseCategory $expenseCategory)
    {
        //
    }
}
