<?php

namespace App\Http\Requests\InvoiceItem;

use App\EBilling\Utbms;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for InvoiceItemController::store(). Authorization is declared on the route (authz:*), not here. */
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
            'utbms_task_code' => 'nullable|string|in:'.implode(',', array_keys(Utbms::TASKS)),   // FUN-13
            'utbms_activity_code' => 'nullable|string|in:'.implode(',', array_keys(Utbms::ACTIVITIES)),   // FUN-13
            'utbms_expense_code' => 'nullable|string|in:'.implode(',', array_keys(Utbms::EXPENSES)),   // FUN-13
            'invoice_id' => 'required|integer|exists:invoices,id',
            'description' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:0',
            'rate' => 'required|numeric|min:0',
            'tax' => 'required_without:tax_rate_id|nullable|numeric|min:0',
            'tax_rate_id' => 'nullable|integer|exists:tax_rates,id',   // DATA-14: the tax is worked out from the rate
        ];
    }
}
