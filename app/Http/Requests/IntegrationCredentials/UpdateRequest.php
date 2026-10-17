<?php

namespace App\Http\Requests\IntegrationCredentials;

use App\Secrets\IntegrationCredentials;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for IntegrationCredentialsController::update(): one optional string per field of the provider. */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = IntegrationCredentials::PROVIDERS[(string) $this->route('provider')] ?? [];

        return collect($fields)->mapWithKeys(fn ($k, $n) => [$n => 'sometimes|nullable|string|max:255'])->all();
    }
}
