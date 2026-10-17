<?php

namespace App\Http\Controllers;

use App\Matters\CaseTypes;
use Illuminate\Http\JsonResponse;

/** DATA-12: the firm's matter types, for the matter form's suggestions. */
class CaseTypeController extends Controller
{
    public function options(CaseTypes $types): JsonResponse
    {
        return $this->response(true, 'success', $types->options(), 200);
    }
}
