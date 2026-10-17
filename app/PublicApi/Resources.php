<?php

namespace App\PublicApi;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Hearing;
use Carbon\Carbon;

/**
 * PLAT-11: the ONLY place that decides which fields leave the system through the public API and webhooks. Explicit allow-lists:
 * a column added to a table later is never exposed by accident. Free-text legal content (matter descriptions, hearing notes and
 * outcomes) and opposing-party details are deliberately not included.
 */
class Resources
{
    // DATA-9: every identifier here is the record's ULID public id; integer database ids never leave the system.

    public static function client(Client $c): array
    {
        return ['id' => $c->publicId(), 'name' => $c->name, 'phone_number' => $c->phone_number, 'extra_phone_number' => $c->extra_phone_number, 'address' => $c->address,
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String()];
    }

    public static function matter(Cases $c): array
    {
        return ['id' => $c->publicId(), 'case_number' => $c->case_number, 'case_type' => $c->case_type, 'client_id' => $c->client?->publicId(), 'status' => $c->lifecycle_status,
            'start_date' => self::date($c->start_date), 'end_date' => self::date($c->end_date),
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String()];
    }

    public static function hearing(Hearing $h): array
    {
        $type = $h->hearingTypeName;

        return ['id' => $h->publicId(), 'case_id' => $h->case?->publicId(), 'hearing_date' => self::date($h->hearing_date),
            // TEN-11: a system hearing type has a stable catalog key; a firm's own type has none.
            'hearing_type' => $type ? ['key' => $type->catalog_key, 'name' => $type->displayName()] : null,
            'created_at' => $h->created_at?->toIso8601String(), 'updated_at' => $h->updated_at?->toIso8601String()];
    }

    private static function date($v): ?string
    {
        return $v === null ? null : Carbon::parse($v)->toDateString();
    }
}
