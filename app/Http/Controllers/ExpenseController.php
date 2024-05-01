<?php

namespace App\Http\Controllers;

use App\Models\Cases;
use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExpenseController extends Controller
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
        $expenses = Expense::all();
        $expenses = $expenses->map(function ($row) {
           return [
               "id" => $row->id,
               "case_id" => $row->case_id,
               "expense_date" => Carbon::parse($row->date)->format('d/m/Y'),
               "amount" => $row->amount,
               "category" => $row->category,
               "category_name" => $row->categoryName ? $row->categoryName->name : "",
               "description" => $row->description,
               "vendor" => $row->vendor,
               "payment_method" => $row->payment_method,
               "invoice_number" => $row->invoice_number,
               "user_id" => $row->user_id,
               "user" => User::find($row->user_id)->name,
               'case' => Cases::find($row->case_id)->case_number
           ];
        });

        return $this->response(true,'success',$expenses,200);
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
        Expense::create([
            "case_id" => $request->case_id,
            "date" => Carbon::createFromFormat('d/m/Y', $request->expense_date)->format('Y-m-d'),
            "amount" => $request->amount,
            "category" => $request->category,
            "description" => $request->description,
            "vendor" => $request->vendor,
            "payment_method" => $request->payment_method,
            "invoice_number" => $request->invoice_number,
            "user_id" => $request->user_id,
            "created_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function show($id): \Illuminate\Http\Response|JsonResponse
    {
        $expenses = Expense::where('case_id', $id)->firstOrFail();

        return $this->response(true,'success',$expenses,200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function edit(Expense $expense)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $expense = Expense::find($id);
        $expense ->update([
            "case_id" => $request->case_id,
            "date" => Carbon::createFromFormat('d/m/Y', $request->expense_date)->format('Y-m-d'),
            "amount" => $request->amount,
            "category" => $request->category,
            "description" => $request->description,
            "vendor" => $request->vendor,
            "payment_method" => $request->payment_method,
            "invoice_number" => $request->invoice_number,
            "user_id" => $request->user_id,
            "updated_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function destroy(Expense $expense)
    {
        //
    }
}
