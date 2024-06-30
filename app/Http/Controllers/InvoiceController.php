<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_INVOICES))
            abort(403);
        if(auth()->user()->hasRole('admin')){
            $invoices = Invoice::withTrashed()->orWhere("status",1)->orwhere("created_by", auth()->user()->id)->get();
        } else {
            $invoices = Invoice::where("created_by", auth()->user()->id)->get();
        }

        $invoices = $invoices->map(function ($row) {
            $status = "archived";
            $delete = false;

            switch ($row->status) {
                case 1:
                    $status = "active";
                    $delete = true;
                    break;
                case 3:
                    $status = "draft";
                    $delete = true;
                    break;
                case 4:
                    $status = "deleted(admin)";
                    break;
                case 5:
                    $status = "deleted(user)";
                    break;

            }

           return [
               "id" => $row->id,
               "case_id" => $row->case_id,
               "case" => $row->case->case_number,
               "invoice_date" => Carbon::parse($row->invoice_date)->format('d/m/Y'),
               "invoice_due_date" => Carbon::parse($row->invoice_due_date)->format('d/m/Y'),
               "client_id" => $row->client_id,
               "client" => Client::find($row->client_id)->name,
               "status" => $status,
               "delete" => $delete,
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
            "client_id" => $request->client_id,
            "status" => 3,
            "created_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Invoice  $invoice
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $invoice = Invoice::find($id);
        $invoice = [
            "id" => $invoice->id,
            "case" => $invoice->case->case_number,
            "client" => $invoice->client->name,
            "invoice_date" => Carbon::parse($invoice->invoice_date)->format('d/m/Y'),
            "invoice_due_date" => Carbon::parse($invoice->invoice_due_date)->format('d/m/Y'),
        ];

        return $this->response(true,'success', $invoice, 200);
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
            "client_id" => $request->client_id,
            "updated_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    public function sendToAdmin($id): \Illuminate\Http\Response|JsonResponse
    {
        $invoice = Invoice::find($id);
        $invoice->update([
            "status" => 1,
            "updated_by" => auth()->user()->id
        ]);

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Invoice  $invoice
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $invoice = Invoice::find($id);
        if(auth()->user()->hasPermissionTo(ModulePermissions::DELETE_INVOICES)) {
            //maybe send to creator that the invoice has been rejected by admin

            $invoice->deleted_by = auth()->user()->id;
            $invoice->status = 4; //Deleted By admin
            $invoice->save();
            $invoice->delete();
        } else if($invoice->created_by === auth()->user()->id && $invoice->status === 4) {

            $invoice->deleted_by = auth()->user()->id;
            $invoice->status = 5; //Deleted By creator
            $invoice->save();
            $invoice->delete();
        } else {
            return $this->response(false,"error", null, 404);
        }

        return $this->index();
    }

    public function preview($id): JsonResponse
    {
        $items = InvoiceItem::where("invoice_id",$id)->get();
        $invoice = Invoice::find($id);
        $total = InvoiceItem::where("invoice_id", $id)
            ->selectRaw("SUM(tax) as total_tax, SUM(total_amount) as totals")
            ->first();


        $items = $items->map(function($row) {
            return [
                "item" => $row->description,
                "quantity" => $row->quantity,
                "price" => $row->rate,
                "amount" => $row->total_amount
            ];
        });

        $invoice = [
            "number"=> sprintf("%04d", $invoice->id),
            "date" => $invoice->invoice_date,
            "due_date"=>$invoice->invoice_due_date,
            "client" => $invoice->client->name,
            "client_address" => $invoice->client->address,
            "postShow" => $invoice->status === 3
        ];

        return $this->response(true,'success',[$items,$invoice,$total],200);
    }
}
