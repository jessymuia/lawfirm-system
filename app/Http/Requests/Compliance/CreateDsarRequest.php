<?php

namespace App\Http\Requests\Compliance;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ComplianceController::createDsar(). Authorization is declared on the route (authz:*), not here. */
class CreateDsarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['type' => 'required|in:access,erasure', 'client_id' => 'required|integer', 'requester_name' => 'required|string|max:255', 'requester_email' => 'nullable|email', 'received_at' => 'nullable|date'];
    }
}
