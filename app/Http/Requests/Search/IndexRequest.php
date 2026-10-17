<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for SearchController::index(). Authorization is declared on the route (authz:*), not here. */
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
            'q' => 'required|string|min:2|max:255',
        ];
    }
}
