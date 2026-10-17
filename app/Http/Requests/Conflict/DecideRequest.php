<?php

namespace App\Http\Requests\Conflict;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-9: input rules for ConflictController::decide(). Proceeding past a possible conflict always needs a reason. */
class DecideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => 'required|in:proceed,declined',
            'note' => 'required|string|min:10|max:1000',
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Record why: who was consulted, what consent or screen was put in place.', 'note.min' => 'Record why in a sentence or two.'];
    }
}
