<?php

namespace App\Http\Requests\Concerns;

/**
 * ENG-8 / TEN-11: on a platform-owned (system) catalog row only a local `alias` may change, and the controller
 * validates that itself (HandlesCatalogRows::updateCatalogAlias). The firm's own rows get the full rules.
 *
 * @property-read string $catalogModel
 * @property-read string $catalogParameter
 */
trait CatalogUpdateRules
{
    use RouteRecord;

    protected function isSystemRow(): bool
    {
        $model = $this->catalogModel;
        $id = $this->routeId($this->catalogParameter);

        // no record (rules read outside a request, e.g. by the OpenAPI generator, ENG-12): the firm-row rules, without a query
        return $id !== null && (bool) $model::whereKey($id)->value('is_system');
    }
}
