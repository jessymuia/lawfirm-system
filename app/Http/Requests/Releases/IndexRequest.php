<?php

namespace App\Http\Requests\Releases;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ReleasesController::index(). Authorization is declared on the route (authz:*), not here. */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['since' => ['sometimes', 'string', 'max:64']];
    }
}
