<?php

namespace App\Actions;

use Illuminate\Http\JsonResponse;

/**
 * ENG-9: a business rule said no (an invoice that is no longer a draft, a payment above the balance, an illegal status
 * change). Thrown by actions whoever calls them; over HTTP it becomes the same {success:false, message} answer the
 * controllers always gave, with its status (422 unless the rule says otherwise).
 */
class ActionException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $body = ['success' => false, 'message' => $this->getMessage(), 'data' => null];

        return response()->json($this->reason !== null ? $body + ['code' => $this->reason] : $body, $this->status);
    }
}
