<?php

namespace App\Http\Requests\Expense;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ExpenseController::store(). Authorization is declared on the route (authz:*), not here. */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'utbms_expense_code' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::EXPENSES)),   // FUN-13
            'case_id' => 'required|integer|exists:cases,id',
            'expense_date' => 'required|date_format:d/m/Y',
            'amount' => 'required|numeric|min:0',
            'category' => 'required|integer|exists:expense_categories,id',
            'description' => 'required|string|max:255',
            'vendor' => 'required|string|max:255',
            'payment_method' => 'required|string|max:255',
            'invoice_number' => 'nullable|string|max:255',
            'user_id' => 'required|integer|exists:users,id',
        ];
    }
}
