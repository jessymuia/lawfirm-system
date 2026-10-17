<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Http\Requests\Expense\DestroyManyRequest;
use App\Http\Requests\Expense\StoreRequest;
use App\Http\Requests\Expense\UpdateRequest;
use App\Http\Resources\V1\ExpenseResource;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_EXPENSES)) {
            abort(403);
        }

        $query = Expense::with(['categoryName', 'user', 'case'])->orderBy('id', 'desc');

        // Bug fix: the case detail page's "Case Expenses" card called this
        // endpoint with no filter at all, so it displayed every expense
        // in the entire firm on every case's page, not just that case's
        // own expenses — directly contradicting the card's own heading.
        // This filter is opt-in (only applied when case_id is actually
        // passed), so the plain expenses list page's existing behaviour
        // is unchanged.
        if (request()->filled('case_id')) {
            $query->where('case_id', request()->input('case_id'));
        }

        // Note: 'expense_date' in the response is a Carbon-formatted alias
        // of the real `date` column (see ExpenseResource) — it can't be
        // used as a sort column here since Eloquent would try to
        // ORDER BY a column that doesn't exist. Sortable columns below
        // are limited to keys that are a 1:1 match with a real column.
        $expenses = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => ExpenseResource::make($row)->resolve(),
            25,
            ['amount', 'description', 'vendor', 'payment_method', 'invoice_number'],
            ['description', 'vendor', 'invoice_number']
        );

        return $this->response(true, 'success', $expenses, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_EXPENSES)) {
            abort(403);
        }

        $expense = Expense::create([
            'case_id' => $request->case_id,
            'date' => Carbon::createFromFormat('d/m/Y', $request->expense_date)->format('Y-m-d'),
            'amount' => $request->amount,
            'category' => $request->category,
            'description' => $request->description,
            'vendor' => $request->vendor,
            'payment_method' => $request->payment_method,
            'invoice_number' => $request->invoice_number,
            'user_id' => $request->user_id,
            'utbms_expense_code' => $request->input('utbms_expense_code'),   // FUN-13
        ]);

        return $this->response(true, 'success', ExpenseResource::make($expense->load(['categoryName', 'user', 'case']))->resolve(), 201);
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show($id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_EXPENSES)) {
            abort(403);
        }

        $expenses = Expense::where('case_id', $id)->firstOrFail();

        return $this->response(true, 'success', $expenses, 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_EXPENSES)) {
            abort(403);
        }

        $expense = Expense::findOrFail($id);
        $expense->update([
            'case_id' => $request->case_id,
            'date' => Carbon::createFromFormat('d/m/Y', $request->expense_date)->format('Y-m-d'),
            'amount' => $request->amount,
            'category' => $request->category,
            'description' => $request->description,
            'vendor' => $request->vendor,
            'payment_method' => $request->payment_method,
            'invoice_number' => $request->invoice_number,
            'user_id' => $request->user_id,
            'updated_by' => auth()->user()->id,
        ] + ($request->exists('utbms_expense_code') ? $request->only('utbms_expense_code') : []));   // FUN-13: only when sent

        return $this->response(true, 'success', ExpenseResource::make($expense->fresh(['categoryName', 'user', 'case']))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_EXPENSES)) {
            abort(403);
        }

        $expense = Expense::findOrFail($id);
        $expense->deleted_by = auth()->user()->id;
        $expense->save();

        $expense->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * UI-20: bulk delete — see CasesController::destroyMany for the
     * general pattern this mirrors.
     */
    public function destroyMany(DestroyManyRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_EXPENSES)) {
            abort(403);
        }

        $expenses = Expense::whereIn('id', $request->input('ids'))->get();
        foreach ($expenses as $expense) {
            $expense->deleted_by = auth()->user()->id;
            $expense->save();
            $expense->delete();
        }

        return $this->response(true, 'success', ['deleted' => $expenses->pluck('id')], 200);
    }
}
