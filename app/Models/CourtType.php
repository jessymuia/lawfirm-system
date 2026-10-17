<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Catalog\CatalogRow;

/**
 * @property int $id
 * @property string $name
 * @property int $status
 * @property int $archive
 * @property string|null $catalog_key
 * @property bool $is_system
 * @property string|null $alias
 */
class CourtType extends DefaultAppModel
{
    use CatalogRow;

    protected $fillable = [
        'name',
        'updated_by',
        'deleted_by',
    ];
}
