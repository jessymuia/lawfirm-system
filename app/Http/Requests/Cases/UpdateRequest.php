<?php

namespace App\Http\Requests\Cases;

use App\Http\Requests\Concerns\RouteRecord;
use App\Models\Cases;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for CasesController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use RouteRecord;

    protected string $recordModel = Cases::class;

    protected string $recordParameter = 'case';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->routeId('case');

        return [
            'client_matter_id' => ['nullable', 'string', 'max:64', 'not_regex:/[|\[\]]/'],   // FUN-13
            'case_number' => 'required|string|max:255|unique:cases,case_number,'.$id,
            'description' => 'required|string',
            'client_id' => 'required|integer|exists:clients,id',
            'assigned_to' => 'required|integer|exists:users,id',
            'start_date' => 'required|date_format:d/m/Y',
            'end_date' => 'nullable|date_format:d/m/Y|after_or_equal:start_date',
            'case_type' => 'required|string|max:255',
            'police_station' => 'required|string|max:255',
            'court_id' => 'required|integer|exists:courts,id',
            'opposing_party' => 'required|string|max:255',
            'office_id' => 'nullable|integer|exists:offices,id,deleted_at,NULL',               // FUN-18
            'practice_group_id' => 'nullable|integer|exists:practice_groups,id,deleted_at,NULL',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['case_number.unique' => 'Case number already exists'];
    }
}
