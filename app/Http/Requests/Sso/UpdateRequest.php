<?php

namespace App\Http\Requests\Sso;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for SsoController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $saml = $this->input('protocol') === 'saml';

        return [
            'protocol' => 'sometimes|in:oidc,saml',
            'saml_sso_url' => [$saml ? 'required' : 'nullable', 'url', 'max:500'],
            'saml_certificate' => ['nullable', 'string', 'max:20000'],
            'issuer' => $saml ? 'required|string|max:500' : 'required|url|max:500',
            'client_id' => ($saml ? 'nullable' : 'required').'|string|max:255', 'client_secret' => 'nullable|string|max:1000',
            'enabled' => 'sometimes|boolean', 'enforce' => 'sometimes|boolean', 'idp_enforces_mfa' => 'sometimes|boolean',
            'allowed_email_domains' => 'sometimes|array|max:50', 'allowed_email_domains.*' => 'string|max:255',
        ];
    }
}
