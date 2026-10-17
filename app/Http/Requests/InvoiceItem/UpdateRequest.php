<?php

namespace App\Http\Requests\InvoiceItem;

use App\Models\InvoiceItem;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for InvoiceItemController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\RouteRecord;

    protected string $recordModel = \App\Models\InvoiceItem::class;

    protected string $recordParameter = 'invoiceItem';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'utbms_task_code' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::TASKS)),   // FUN-13
            'utbms_activity_code' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::ACTIVITIES)),   // FUN-13
            'utbms_expense_code' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::EXPENSES)),   // FUN-13
            'description' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:0',
            'rate' => 'required|numeric|min:0',
            'tax' => 'required_without:tax_rate_id|nullable|numeric|min:0',
            'tax_rate_id' => 'nullable|integer|exists:tax_rates,id',   // DATA-14: the tax is worked out from the rate
            'invoice_id' => 'required|integer|exists:invoices,id',
        ];
    }
}
