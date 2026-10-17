<?php

namespace App\Enums;

use Illuminate\Validation\Rules\Enum;

/**
 * DATA-12: what every domain enum offers: its values (for validation messages, migrations and the API) and its rule.
 *
 * @mixin \BackedEnum
 */
trait EnumValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public static function rule(): Enum
    {
        return new Enum(self::class);
    }
}
