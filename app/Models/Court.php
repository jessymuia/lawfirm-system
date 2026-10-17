<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Catalog\CatalogRow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property int $type
 * @property int $status
 * @property int $archive
 * @property string|null $catalog_key
 * @property bool $is_system
 * @property string|null $alias
 * @property-read CourtType|null $courtType
 */
class Court extends DefaultAppModel
{
    use CatalogRow, HasFactory;

    protected $fillable = [
        'name',
        'type',
        'updated_by',
        'deleted_by',
    ];

    /** @return BelongsTo<CourtType, $this> */
    public function courtType(): BelongsTo
    {
        return $this->belongsTo(CourtType::class, 'type')->withTrashed();
    }
}
