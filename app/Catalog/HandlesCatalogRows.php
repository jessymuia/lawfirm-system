<?php

namespace App\Catalog;

use App\Models\Court;
use App\Models\CourtType;
use App\Models\HearingType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TEN-11: shared controller behaviour for tables that mix system catalog rows and firm rows.
 * The model guard (CatalogRow) is the enforcement; this only turns a firm's edit of a
 * system row into the one change it is allowed to make, its local alias.
 */
trait HandlesCatalogRows
{
    /** Update a system row: only `alias` is accepted (null or empty clears it). */
    protected function updateCatalogAlias(Request $request, Court|CourtType|HearingType $row): Court|CourtType|HearingType
    {
        if (! $request->exists('alias')) {
            throw new SystemCatalogException('"'.$row->name.'" is system reference data and cannot be renamed or changed. Send `alias` to set a local name for it.');
        }

        $table = $row->getTable();
        $request->validate([
            'alias' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) use ($table, $row) {
                $value = mb_strtolower(trim((string) $value));
                if ($value === '') {
                    return;
                }
                $taken = DB::table($table)
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $row->getKey())
                    ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$value])->orWhereRaw('LOWER(alias) = ?', [$value]))
                    ->exists();
                if ($taken) {
                    $fail('Another entry already uses this name.');
                }
            }],
        ]);

        $alias = trim((string) $request->input('alias'));
        $row->alias = $alias === '' || $alias === $row->name ? null : $alias;
        $row->updated_by = auth()->id();
        $row->save();

        return $row;
    }

    /** Refuse before the controller stamps deleted_by, so the firm gets the deletion message rather than an update one. */
    protected function refuseSystemDelete(Court|CourtType|HearingType $row): void
    {
        if ($row->is_system) {
            throw new SystemCatalogException('"'.$row->name.'" is system reference data and cannot be deleted.');
        }
    }
}
