<?php

namespace App\Matters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/** SEC-11: a client-level screen hides that client from the screened user. (Matter-level screens leave the client visible.) */
class ClientWallScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }
        $clients = MatterAccess::walls()->for($user->getKey())['clients'];
        if ($clients) {
            $builder->whereNotIn($model->qualifyColumn('id'), array_keys($clients));
        }
    }
}
