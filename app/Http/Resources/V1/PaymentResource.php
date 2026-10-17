<?php

namespace App\Http\Resources\V1;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Payment. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from PaymentController::formatRow().
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'public_id' => 'string',
        'receipt_number' => '?string',
        'invoice_id' => 'int',
        'invoice_number' => 'string',
        'amount' => 'decimal',
        'payment_date' => 'date',
        'method' => 'string',
        'reference_number' => '?string',
        'received_by' => 'string',
        'notes' => '?string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Payment $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'public_id' => $row->publicId(),   // DATA-9
            'receipt_number' => $row->receipt_number,
            'invoice_id' => $row->invoice_id,
            'invoice_number' => $row->invoice->invoice_number ?? 'Unknown invoice',
            'amount' => $row->amount,
            'payment_date' => Carbon::parse($row->payment_date)->format('d/m/Y'),
            'method' => $row->method,
            'reference_number' => $row->reference_number,
            'received_by' => $row->receivedBy->name ?? 'Unknown user',
            'notes' => $row->notes,
        ];
    }
}
