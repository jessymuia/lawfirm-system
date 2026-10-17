<?php

namespace App\Http\Requests\Client;

use App\Http\Requests\Concerns\RouteRecord;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ClientController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use RouteRecord;

    protected string $recordModel = \App\Models\Client::class;

    protected string $recordParameter = 'client';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->routeId('client');

        return [
            'ebilling_client_id' => ['nullable', 'string', 'max:64', 'not_regex:/[|\[\]]/'],   // FUN-13
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20|unique:clients,phone_number,'.$id.',id,deleted_at,NULL',
            'extra_phone_number' => 'nullable|string|max:20',
            'address' => 'required|string|max:255',
            'advocate' => 'required|integer|exists:users,id',
        ];
    }
}
