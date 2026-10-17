<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttorneyController extends Controller
{
    public function getAdvocateUsers(Request $request): JsonResponse
    {
        // UI-16: same search+limit treatment as clientsDropDown.
        $query = User::query()->orderBy('name');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('name', 'like', '%'.$search.'%');
        }

        $advocateUsers = $query->limit((int) $request->input('per_page', 20))->get();

        $advocateUsers = $advocateUsers->map(function ($row) {
            return [
                'id' => $row->id,
                'name' => $row->name,
            ];
        });

        return $this->response(true, 'success', $advocateUsers, 200);
    }
}
