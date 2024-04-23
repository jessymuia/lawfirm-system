<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use Carbon\Carbon;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
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
        $invoices = Invoice::all();
        $invoices = $invoices->map(function ($row) {
           return [
               "id" => $row->id,
               "case_id" => $row->case_id,
               "case" => $row->case->case_number,
               "invoice_date" => Carbon::parse($row->invoice_due_date)->format('d/m/Y'),
               "invoice_due_date" => Carbon::parse($row->invoice_due_date)->format('d/m/Y'),
               "client_id" => $row->client_id,
               "client" => Client::find($row->client_id)->name,
           ];
        });

        return $this->response(true,'success', $invoices, 200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        Invoice::create([
            "case_id" => $request->case_id,
            "invoice_date" => Carbon::createFromFormat('d/m/Y', $request->invoice_date)->format('Y-m-d'),
            "invoice_due_date" => Carbon::createFromFormat('d/m/Y', $request->invoice_due_date)->format('Y-m-d'),
            "client_id" => $request->client_id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Invoice  $invoice
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {

    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Invoice  $invoice
     * @return \Illuminate\Http\Response
     */
    public function edit(Invoice $invoice)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Invoice  $invoice
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $invoice = Invoice::find($id);
        $invoice->update([
            "case_id" => $request->case_id,
            "invoice_date" => Carbon::createFromFormat('d/m/Y', $request->invoice_date)->format('Y-m-d'),
            "invoice_due_date" => Carbon::createFromFormat('d/m/Y', $request->invoice_due_date)->format('Y-m-d'),
            "client_id" => $request->client_id
        ]);

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Invoice  $invoice
     * @return \Illuminate\Http\Response
     */
    public function destroy(Invoice $invoice)
    {
        //
    }
}
