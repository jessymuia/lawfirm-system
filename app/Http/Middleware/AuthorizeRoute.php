<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * ENG-11: a route's explicit authorization declaration.
 *
 *   authz:permission,<name>  enforced here, before the controller runs
 *   authz:authenticated      any signed-in user (self-service endpoints)
 *   authz:policy,<ability>,<Model>[,<route param>]
 *                            the model's Policy (app/Policies) decides, before the controller runs. With a route
 *                            parameter the record is loaded (id or public id, through the model's scopes, so a
 *                            record the user cannot reach is a 404) and checked; without one the check is
 *                            class-level (viewAny, create, deleteAny…).
 *
 * Together with RequireAuthzDeclaration, a route with no declaration is denied. There is no "the controller checks
 * it itself" mode any more (the last `authz:inline` routes moved to policies), so every route's rule is visible here.
 */
class AuthorizeRoute
{
    public function handle(Request $request, Closure $next, string $mode, string ...$args)
    {
        switch ($mode) {
            case 'authenticated':
                abort_unless($request->user() !== null, 401);
                break;

            case 'policy':
                $user = $request->user();
                abort_unless($user !== null, 401);
                [$ability, $model] = [$args[0] ?? '', $args[1] ?? ''];
                $class = str_contains($model, '\\') ? $model : 'App\\Models\\'.$model;
                if ($ability === '' || ! is_subclass_of($class, Model::class)) {
                    throw new LogicException("authz:policy needs an ability and a model, got [{$ability}, {$model}].");
                }
                $target = $class;
                if (isset($args[2])) {
                    $value = $request->route($args[2]);
                    $target = $value instanceof Model ? $value : ((new $class)->resolveRouteBinding($value) ?? abort(404));
                }
                Gate::forUser($user)->authorize($ability, $target);
                break;

            case 'permission':
                $user = $request->user();
                abort_unless($user !== null, 401);
                abort_unless($args && $user->checkPermissionTo($args[0]), 403);
                break;

            default:
                // A typo must never silently become "allow".
                throw new LogicException("Unknown authz mode [{$mode}].");
        }

        return $next($request);
    }
}
