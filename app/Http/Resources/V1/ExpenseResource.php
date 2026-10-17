<?php

namespace App\Http\Resources\V1;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Expense. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from ExpenseController::formatRow().
 *
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'case_id' => 'int',
        'expense_date' => 'date',
        'amount' => 'decimal',
        'category' => 'int',
        'category_name' => 'string',
        'description' => 'string',
        'vendor' => 'string',
        'utbms_expense_code' => '?string',
        'payment_method' => 'string',
        'invoice_number' => '?string',
        'user_id' => 'int',
        'user' => 'string',
        'case' => 'string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Expense $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'case_id' => $row->case_id,
            'expense_date' => Carbon::parse($row->date)->format('d/m/Y'),
            'amount' => $row->amount,
            'category' => $row->category,
            'category_name' => $row->categoryName ? $row->categoryName->name : '',
            'description' => $row->description,
            'vendor' => $row->vendor,
            'utbms_expense_code' => $row->utbms_expense_code,   // FUN-13
            'payment_method' => $row->payment_method,
            'invoice_number' => $row->invoice_number,
            'user_id' => $row->user_id,
            'user' => $row->user->name ?? 'Unknown user',
            'case' => $row->case->case_number ?? 'Unknown case',
        ];
    }
}
