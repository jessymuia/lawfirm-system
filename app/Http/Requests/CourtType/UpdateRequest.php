<?php

namespace App\Http\Requests\CourtType;

use App\Http\Requests\Concerns\CatalogUpdateRules;
use App\Models\CourtType;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for CourtTypeController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use CatalogUpdateRules;

    protected string $recordModel = \App\Models\CourtType::class;

    protected string $recordParameter = 'courttype';

    protected string $catalogModel = CourtType::class;

    protected string $catalogParameter = 'courttype';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->isSystemRow() ? [] : [
            'name' => 'required|string|max:255|unique:court_types,name,'.$this->routeId('courttype'),
        ];
    }
}
