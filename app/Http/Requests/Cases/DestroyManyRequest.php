<?php

namespace App\Http\Requests\Cases;

use App\Models\Cases;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for CasesController::destroyMany(). Authorization is declared on the route (authz:*), not here. */
class DestroyManyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ids' => 'required|array|min:1', 'ids.*' => 'integer'];
    }
}
