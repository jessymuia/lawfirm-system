<?php

namespace App\Http\Requests\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for TaskController::store(). Authorization is declared on the route (authz:*), not here. */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => 'required|string',
            'title' => 'required|string|max:255',
            'assigned_to' => 'required|integer|exists:users,id',
            'due_date' => 'required|date_format:d/m/Y',
            'priority' => ['required', TaskPriority::rule()],
            'status' => ['required', TaskStatus::rule()],
        ];
    }
}
