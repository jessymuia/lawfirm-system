<?php

namespace App\Models\Concerns;

use App\Matters\CaseTypes;

/**
 * DATA-12: a matter's typed case_type is resolved against the firm's case_types list (found ignoring case and
 * spacing, or added) on every save, whoever saves, so case_type_id is always set and the text takes the list's
 * spelling.
 */
trait HasCaseType
{
    public static function bootHasCaseType(): void
    {
        static::saving(function ($case) {
            if (! $case->isDirty('case_type') && $case->case_type_id) {
                return;
            }
            $type = trim((string) $case->case_type) === '' ? null : app(CaseTypes::class)->resolve((string) $case->case_type);
            $case->forceFill(['case_type_id' => $type?->id, 'case_type' => $type !== null ? $type->name : $case->case_type]);
        });
    }
}
