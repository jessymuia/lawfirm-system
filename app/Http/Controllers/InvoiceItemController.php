<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceItemController extends Controller
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
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            "invoice_id"=>"required",
            "description"=>"required",
            "quantity"=>"required",
            "rate"=>"required",
            "tax" =>"required",
            "total_amount"=>"required"
        ]);

       InvoiceItem::create([
            "invoice_id"=>$request->invoice_id,
            "description"=>$request->description,
            "quantity" => $request->quantity,
            "rate" => $request->rate,
            "tax" => $request->tax,
            "total_amount" => $request->total_amount,
            "created_by"=>auth()->user()->id
        ]);

        $items = InvoiceItem::where("invoice_id",$request->invoice_id)->orderBy("id","desc")->get();

        return $this->response(true,'success',$items,200);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\InvoiceItem  $invoiceItem
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $items = InvoiceItem::where("invoice_id", $id)->get();
        $invoice = Invoice::where("id", $id)->first();

        return $this->response(true,'success',[$items,$invoice], 200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\InvoiceItem  $invoiceItem
     * @return \Illuminate\Http\Response
     */
    public function edit(InvoiceItem $invoiceItem)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\InvoiceItem  $invoiceItem
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $item = InvoiceItem::find($id);
        $item->update([
            "description"=>$request->description,
            "quantity" => $request->quantity,
            "rate" => $request->rate,
            "tax" => $request->tax,
            "total_amount" => $request->total_amount,
            "updated_by"=>auth()->user()->id
        ]);

        $items = InvoiceItem::where("invoice_id",$request->invoice_id)->orderBy("id","desc")->get();

        return $this->response(true,'success',$items,200);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\InvoiceItem  $invoiceItem
     * @return \Illuminate\Http\Response
     */
    public function destroy($id): \Illuminate\Http\Response|JsonResponse
    {
        $item = InvoiceItem::find($id);
        $item->deleted_by = auth()->user()->id;
        $item->save();
        $item->delete();

        $items = InvoiceItem::where("invoice_id",$request->invoice_id)->orderBy("id","desc")->get();

        return $this->response(true,'success',$items,200);
    }
}
