<?php

namespace App\Http\Requests\PlatformSupport;

use App\SupportAccess\SupportPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformSupportController::request(). Authorization is declared on the route (authz:*), not here. */
class RequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:1000',
            'minutes' => 'nullable|integer|min:5|max:'.SupportPolicy::MAX_MINUTES_CEILING,
            'mode' => 'nullable|in:read_only,full',
        ];
    }
}
