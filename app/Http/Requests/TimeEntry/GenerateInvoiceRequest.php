<?php

namespace App\Http\Requests\TimeEntry;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for TimeEntryController::generateInvoice(). Authorization is declared on the route (authz:*), not here. */
class GenerateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'case_id' => 'required|integer|exists:cases,id',
            'client_id' => 'required|integer|exists:clients,id',
        ];
    }
}
