<?php

namespace App\Models\Concerns;

use InvalidArgumentException;

/**
 * DATA-12: a model's enum columns (its ENUM_COLUMNS: column => enum class) only ever hold one of the enum's values.
 * Checked on every save, whoever saves (a request, an action, a job, a seeder), in addition to the database's own
 * constraint, so an invalid state cannot be written and fails where it is caused.
 */
trait ConstrainedToEnums
{
    public static function bootConstrainedToEnums(): void
    {
        static::saving(function (self $model) {
            foreach (static::ENUM_COLUMNS as $column => $enum) {
                if (! $model->isDirty($column)) {
                    continue;
                }
                $value = $model->getAttribute($column);
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                }
                if ($value !== null && $enum::tryFrom((string) $value) === null) {
                    throw new InvalidArgumentException(sprintf('%s.%s cannot be "%s": it is one of %s.', $model->getTable(), $column, $value, implode(', ', $enum::values())));
                }
            }
        });
    }
}
