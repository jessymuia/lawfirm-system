<?php

namespace App\Http\Requests\Court;

use App\Models\Court;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for CourtController::store(). Authorization is declared on the route (authz:*), not here. */
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
            'name' => 'required|string|max:255',
            'type' => 'required|integer|exists:court_types,id',
        ];
    }
}
