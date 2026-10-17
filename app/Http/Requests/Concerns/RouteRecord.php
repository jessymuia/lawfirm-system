<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * ENG-8: the record a request is about, from its route parameter.
 *
 * A request that names `$recordModel` / `$recordParameter` first makes sure the record is there for this user: it is
 * looked up through the model's own scopes (matter access, ethical walls, soft deletes), and a record the user cannot
 * reach is a 404 before any rule runs, exactly as when the controller validated after findOrFail(). A hidden matter must
 * never answer "your input is invalid" (SEC-10).
 *
 * Every user declares `protected string $recordModel` (a model class) and `protected string $recordParameter`.
 */
trait RouteRecord
{
    protected function prepareForValidation(): void
    {
        $model = $this->recordModel;
        abort_unless($model::query()->whereKey($this->routeId($this->recordParameter))->exists(), 404);
    }

    protected function routeId(string $parameter): mixed
    {
        $value = $this->route($parameter);

        return $value instanceof Model ? $value->getKey() : $value;
    }
}
