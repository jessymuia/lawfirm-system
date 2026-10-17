<?php

namespace App\Http\Requests\Signature;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-14 send a matter document for signature. Every signer is reachable by email or phone. */
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
            'document_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'message' => 'nullable|string|max:1000',
            'in_order' => 'sometimes|boolean',
            'days' => 'nullable|integer|min:1|max:60',
            'signers' => 'required|array|min:1|max:10',
            'signers.*.name' => 'required|string|max:191',
            'signers.*.email' => 'nullable|email|max:191|required_without:signers.*.phone',
            'signers.*.phone' => 'nullable|string|max:32|required_without:signers.*.email',
        ];
    }
}
