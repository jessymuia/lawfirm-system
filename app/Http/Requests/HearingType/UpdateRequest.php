<?php

namespace App\Http\Requests\HearingType;

use App\Http\Requests\Concerns\CatalogUpdateRules;
use App\Models\HearingType;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for HearingTypeController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use CatalogUpdateRules;

    protected string $recordModel = HearingType::class;

    protected string $recordParameter = 'hearingtype';

    protected string $catalogModel = HearingType::class;

    protected string $catalogParameter = 'hearingtype';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->isSystemRow() ? [] : [
            'name' => 'required|string|max:255|unique:hearing_types,name,'.$this->routeId('hearingtype'),
        ];
    }
}
