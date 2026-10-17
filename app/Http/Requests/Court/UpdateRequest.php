<?php

namespace App\Http\Requests\Court;

use App\Http\Requests\Concerns\CatalogUpdateRules;
use App\Models\Court;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for CourtController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use CatalogUpdateRules;

    protected string $recordModel = Court::class;

    protected string $recordParameter = 'court';

    protected string $catalogModel = Court::class;

    protected string $catalogParameter = 'court';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->isSystemRow() ? [] : [
            'name' => 'required|string|max:255',
            'type' => 'required|integer|exists:court_types,id',
        ];
    }
}
