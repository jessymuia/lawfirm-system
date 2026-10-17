<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * DATA-9: a sortable, unguessable ULID for records that leave the system (public API, webhooks, shareable URLs).
 * Integer ids stay internal: they leak volume, collide when firms merge or move, and force remapping on import.
 *
 * - Every new row gets a public_id on create; it never changes afterwards.
 * - Rows from before DATA-9 get one from BackfillPublicIds; publicId() also fills a missing one on first use, so an
 *   install that has not run the backfill yet never exposes null.
 * - Route binding accepts the public id (and, for the SPA's own internal calls, the integer id).
 *
 * @property string|null $public_id
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', self::newPublicId());
            }
        });

        static::updating(function (Model $model) {
            if ($model->getOriginal('public_id') !== null && $model->isDirty('public_id')) {
                throw new \LogicException('A public id never changes.');
            }
        });
    }

    public static function newPublicId(): string
    {
        return (string) Str::ulid();
    }

    public static function isPublicId(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value);
    }

    /** The public id, assigning one (without an audit row or touching updated_at) if this row predates DATA-9. */
    public function publicId(): string
    {
        if (empty($this->public_id)) {
            $id = self::newPublicId();
            static::query()->withoutGlobalScopes()->whereKey($this->getKey())->whereNull('public_id')->update(['public_id' => $id]);
            $this->public_id = static::query()->withoutGlobalScopes()->whereKey($this->getKey())->value('public_id') ?? $id;
            $this->syncOriginalAttribute('public_id');
        }

        return $this->public_id;
    }

    /** @param  Builder<static>  $query */
    public function scopeWherePublicId(Builder $query, string $publicId): Builder
    {
        return $query->where($this->qualifyColumn('public_id'), strtoupper($publicId));
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === null && self::isPublicId($value)) {
            return $this->resolveRouteBindingQuery($this->newQuery(), strtoupper($value), 'public_id')->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
