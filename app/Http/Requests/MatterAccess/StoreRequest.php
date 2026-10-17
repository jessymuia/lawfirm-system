<?php

namespace App\Http\Requests\MatterAccess;

use App\Http\Requests\Concerns\RouteRecord;
use App\Models\Cases;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for MatterAccessController::store(). Authorization is declared on the route (authz:*), not here. */
class StoreRequest extends FormRequest
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
        return [
            'user_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date|after:now',
        ];
    }
}
