<?php

namespace App\Http\Requests\TrustTransaction;

use App\Models\TrustTransaction;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for TrustTransactionController::index(). Authorization is declared on the route (authz:*), not here. */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => 'required|integer|exists:clients,id',
        ];
    }
}
