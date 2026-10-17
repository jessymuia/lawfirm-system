<?php

namespace App\Http\Resources\V1;

use App\Constants\ModulePermissions;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Invoice. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from InvoiceController::formatRow().
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'public_id' => 'string',
        'invoice_number' => '?string',
        'case_id' => '?int',
        'case' => 'string',
        'invoice_date' => 'date',
        'invoice_due_date' => 'date',
        'client_id' => '?int',
        'client_public_id' => '?string',
        'client' => 'string',
        'currency' => 'string',
        'base_currency' => '?string',
        'fx_rate' => '?decimal',
        'base_total_amount' => '?decimal',
        'subtotal' => 'decimal',
        'tax_total' => 'decimal',
        'total_amount' => 'decimal',
        'amount_paid' => 'decimal',
        'workflow_status' => 'string',
        'payment_status' => '?string',
        'status' => 'string',
        'delete' => 'bool',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Invoice $row */
        $row = $this->resource;

        // Admins can void any invoice; a creator may only delete their
        // own invoice while it's still a draft (not yet submitted).
        $delete = auth()->user()->checkPermissionTo(ModulePermissions::DELETE_INVOICES)
            || ($row->created_by === auth()->user()->id && $row->workflow_status === 'draft');

        return [
            'id' => $row->id,
            'public_id' => $row->publicId(),   // DATA-9
            'invoice_number' => $row->invoice_number,
            'case_id' => $row->case_id,
            'case' => $row->case->case_number ?? 'Unknown case',
            'invoice_date' => Carbon::parse($row->invoice_date)->format('d/m/Y'),
            'invoice_due_date' => Carbon::parse($row->invoice_due_date)->format('d/m/Y'),
            'client_id' => $row->client_id,
            'client_public_id' => $row->client?->publicId(),
            'client' => $row->client->name ?? 'Unknown client',
            'currency' => $row->currency,
            'base_currency' => $row->base_currency,          // DATA-14: fixed when the invoice is issued
            'fx_rate' => $row->fx_rate,
            'base_total_amount' => $row->base_total_amount,
            'subtotal' => $row->subtotal,
            'tax_total' => $row->tax_total,
            'total_amount' => $row->total_amount,
            'amount_paid' => $row->amount_paid,
            'workflow_status' => $row->workflow_status,
            'payment_status' => $row->payment_status,
            'status' => $row->trashed() ? 'void' : $row->workflow_status,
            'delete' => $delete,
        ];
    }
}
