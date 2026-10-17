<?php

namespace App\Http\Requests\Platform;

use App\Secrets\IntegrationCredentials;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: an operator enters a firm's own provider credentials. Which fields exist depends on the provider in the address. */
class IntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = array_keys(IntegrationCredentials::PROVIDERS[$this->route('provider')] ?? []);

        return collect($fields)->mapWithKeys(fn ($f) => [$f => 'sometimes|nullable|string|max:255'])->all();
    }
}
