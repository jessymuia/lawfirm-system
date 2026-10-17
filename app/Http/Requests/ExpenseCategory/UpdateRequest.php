<?php

namespace App\Http\Requests\ExpenseCategory;

use App\Http\Requests\Concerns\RouteRecord;
use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ExpenseCategoryController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use RouteRecord;

    protected string $recordModel = ExpenseCategory::class;

    protected string $recordParameter = 'expense_category';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->routeId('expense_category');

        return [
            'name' => 'required|string|max:255|unique:expense_categories,name,'.$id,
        ];
    }
}
