<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Expense;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
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
        if(!Auth::user()->hasPermissionTo(ModulePermissions::VIEW_DASHBOARD))
            abort(403);
        //weekly
        $weekStartDate = Carbon::now()->startOfWeek()->startOfDay();

        $weekEndDate = Carbon::now()->startOfWeek()->subWeek()->endOfDay();

        $clients = Client::all()->count();//whereBetween("created_at", [$weekStartDate,$weekEndDate])->count();
        $cases = Cases::all()->count();
        $expenses = Expense::whereBetween("created_at", [$weekStartDate,$weekEndDate])->sum("amount");
        $invoices = InvoiceItem::all()->sum("total_amount");

        $monthlyCases = [];

        for ($month = 1; $month <= 12; $month++) {
            // Get the start and end dates for the current month
            $startDate = Carbon::now()->startOfMonth()->month($month)->startOfDay();
            $endDate = Carbon::now()->startOfMonth()->month($month)->endOfMonth()->endOfDay();

            // Query your model where the date column is between the start and end dates of the current month
            $monthlyCases[$month-1] = Cases::whereBetween('created_at', [$startDate, $endDate])->count();
        }

        $monthlyClients = [];

        for ($month = 1; $month <= 12; $month++) {
            // Get the start and end dates for the current month
            $startDate = Carbon::now()->startOfMonth()->month($month)->startOfDay();
            $endDate = Carbon::now()->startOfMonth()->month($month)->endOfMonth()->endOfDay();

            // Query your model where the date column is between the start and end dates of the current month
            $monthlyClients[$month-1] = Client::whereBetween('created_at', [$startDate, $endDate])->count();
        }



        return $this->response(true,"success", [$clients,$cases,$invoices,$expenses,$monthlyClients,$monthlyCases], 200);
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
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
