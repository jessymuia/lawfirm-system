<?php

namespace App\Http\Resources\V1;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of ExpenseCategory. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from ExpenseCategoryController::formatRow().
 *
 * @mixin ExpenseCategory
 */
class ExpenseCategoryResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'name' => 'string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ExpenseCategory $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'name' => $row->name,
        ];
    }
}
