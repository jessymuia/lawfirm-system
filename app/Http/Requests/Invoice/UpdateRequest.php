<?php

namespace App\Http\Requests\Invoice;

use App\Http\Requests\Concerns\RouteRecord;
use App\Models\Invoice;
use App\Rules\FirmCurrency;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for InvoiceController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use RouteRecord;

    protected string $recordModel = Invoice::class;

    protected string $recordParameter = 'invoice';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'case_id' => 'required|integer|exists:cases,id',
            'invoice_date' => 'required|date_format:d/m/Y',
            'invoice_due_date' => 'required|date_format:d/m/Y|after_or_equal:invoice_date',
            'client_id' => 'required|integer|exists:clients,id',
            // DATA-14: one of the firm's currencies; the base currency when not given
            'currency' => ['nullable', 'string', 'size:3', new FirmCurrency],
        ];
    }
}
