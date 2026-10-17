<?php

namespace App\Catalog;

use App\Models\Court;
use App\Models\CourtType;
use App\Models\Currency;
use App\Models\HearingType;
use App\Models\TaxRate;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-11: the platform-owned reference catalog every firm gets, and the sync
 * that puts it into the current database.
 *
 * Rules (see docs/SYSTEM_CATALOG.md):
 * - An entry's key is its identity, forever. Never reuse or remove a key: to take
 *   an entry out of use, mark it 'retired' => true (the row stays, so history that
 *   points at it keeps resolving, but it is archived).
 * - Renaming an entry is a data change in this file; the next sync renames the row
 *   in every firm. Firms that want a different label set an alias instead.
 * - sync() is idempotent and only ever touches system rows (plus adopting a firm's
 *   row with exactly the same name as an entry the firm does not have yet).
 *
 * Expense categories are deliberately NOT here: they are each firm's own chart of
 * accounts. ExpenseCategorySeeder only gives a new firm a starter set.
 *
 * Currencies and tax rates (DATA-14) are here too. A firm decides which currencies it uses, which tax rate is its
 * default and which it archives; the sync keeps names, minor units and percentages current and never touches those.
 */
class SystemCatalog
{
    /** court type key => official name */
    public const COURT_TYPES = [
        'supreme_court' => ['name' => 'Supreme Court'],
        'court_of_appeal' => ['name' => 'Court of Appeal'],
        'high_court' => ['name' => 'High Court'],
        'employment_labour_relations_court' => ['name' => 'Employment and Labour Relations Court'],
        'environment_land_court' => ['name' => 'Environment and Land Court'],
        'magistrate_court' => ['name' => 'Magistrate Court'],
        'kadhis_court' => ['name' => "Kadhi's Court"],
        'family_court' => ['name' => 'Family Court'],
        'small_claims_court' => ['name' => 'Small Claims Court'],
        'juvenile_court' => ['name' => 'Juvenile Court'],
        'tribunal' => ['name' => 'Tribunal'],
        // Fallback the DATA-1 migration points orphaned courts at; keeping it in the catalog means every firm has it.
        'unclassified' => ['name' => 'Unclassified'],
    ];

    /** court key => official name + court type key. Superior courts and the main Nairobi stations only; firms add the rest. */
    public const COURTS = [
        'ke_supreme_court' => ['name' => 'Supreme Court of Kenya', 'type' => 'supreme_court'],
        'ke_court_of_appeal_nairobi' => ['name' => 'Court of Appeal at Nairobi', 'type' => 'court_of_appeal'],
        'ke_high_court_nairobi_milimani' => ['name' => 'High Court at Nairobi (Milimani Law Courts)', 'type' => 'high_court'],
        'ke_elrc_nairobi' => ['name' => 'Employment and Labour Relations Court at Nairobi', 'type' => 'employment_labour_relations_court'],
        'ke_elc_nairobi' => ['name' => 'Environment and Land Court at Nairobi', 'type' => 'environment_land_court'],
        'ke_milimani_chief_magistrate' => ['name' => "Chief Magistrate's Court, Milimani", 'type' => 'magistrate_court'],
    ];

    /** hearing type key => official name */
    public const HEARING_TYPES = [
        'plea_taking' => ['name' => 'Plea Taking'],
        'pretrial_conference' => ['name' => 'Pretrial Conference'],
        'substantive_application' => ['name' => 'Hearing of Substantive applications'],
        'case_management_conference' => ['name' => 'Case Management conference'],
        'trial' => ['name' => 'Trial'],
        'sentence_hearing' => ['name' => 'Sentence Hearing'],
        'appeal_hearing' => ['name' => 'Appeal hearing'],
        'interlocutory_application' => ['name' => 'Interlocutory Application'],
        'judgment_delivery' => ['name' => 'Judgment delivery'],
        'evidence_hearing' => ['name' => 'Evidence hearing'],
        'mention' => ['name' => 'Mention'],
        'ruling' => ['name' => 'Ruling'],
        // Fallback the DATA-2 migration points orphaned hearings at.
        'unclassified' => ['name' => 'Unclassified'],
    ];

    /**
     * DATA-14: currencies (ISO 4217 code => name, minor units, symbol). Firms enable the ones they invoice in; the sync never
     * changes whether a currency is enabled.
     */
    /** @var array<string, array{name: string, minor_units: int, symbol: string}> */
    public const CURRENCIES = [
        'KES' => ['name' => 'Kenyan Shilling', 'minor_units' => 2, 'symbol' => 'KSh'],
        'UGX' => ['name' => 'Ugandan Shilling', 'minor_units' => 0, 'symbol' => 'USh'],
        'TZS' => ['name' => 'Tanzanian Shilling', 'minor_units' => 2, 'symbol' => 'TSh'],
        'RWF' => ['name' => 'Rwandan Franc', 'minor_units' => 0, 'symbol' => 'FRw'],
        'USD' => ['name' => 'US Dollar', 'minor_units' => 2, 'symbol' => '$'],
        'EUR' => ['name' => 'Euro', 'minor_units' => 2, 'symbol' => '€'],
        'GBP' => ['name' => 'Pound Sterling', 'minor_units' => 2, 'symbol' => '£'],
        'ZAR' => ['name' => 'South African Rand', 'minor_units' => 2, 'symbol' => 'R'],
    ];

    /**
     * DATA-14: tax rates (key => name, percentage, jurisdiction). `default` makes it the firm's default only if the firm has
     * none yet; after that which rate is the default, and whether a rate is archived, are the firm's choice.
     */
    /** @var array<string, array{name: string, rate: int|float, jurisdiction: string, default?: bool, retired?: bool}> */
    public const TAX_RATES = [
        'ke_vat_standard' => ['name' => 'VAT', 'rate' => 16, 'jurisdiction' => 'KE', 'default' => true],   // VAT Act 2013, s. 5(2)(b)
        'ke_vat_zero_rated' => ['name' => 'VAT zero-rated', 'rate' => 0, 'jurisdiction' => 'KE'],
        'ke_vat_exempt' => ['name' => 'VAT exempt', 'rate' => 0, 'jurisdiction' => 'KE'],
    ];

    private static bool $syncing = false;

    /** True only while sync() is writing: the one context allowed to create, change or delete system rows. */
    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

    /** Whether this database has the catalog columns yet (a lagging install may run the code before the migration). */
    public static function installed(): bool
    {
        foreach (['court_types', 'courts', 'hearing_types'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'catalog_key')) {
                return false;
            }
        }

        return true;
    }

    private static function moneyInstalled(): bool
    {
        return Schema::hasTable('currencies') && Schema::hasColumn('currencies', 'is_system') && Schema::hasColumn('tax_rates', 'catalog_key');
    }

    /**
     * Bring the current database's system rows in line with the catalog.
     *
     * @return array<string, array{created:int, adopted:int, updated:int, unchanged:int}>|null null when the schema is not migrated yet
     */
    public static function sync(bool $dryRun = false): ?array
    {
        if (! self::installed()) {
            return null;
        }

        $report = [];
        $run = function () use (&$report) {
            $report['court_types'] = self::syncTable(CourtType::class, self::COURT_TYPES, fn () => []);

            $typeIds = CourtType::withTrashed()->whereNotNull('catalog_key')->pluck('id', 'catalog_key');
            $report['courts'] = self::syncTable(Court::class, self::COURTS, fn (array $entry) => ['type' => $typeIds[$entry['type']]]);

            $report['hearing_types'] = self::syncTable(HearingType::class, self::HEARING_TYPES, fn () => []);

            if (self::moneyInstalled()) {   // DATA-14 tables; an install may run this code before their migration
                $report['currencies'] = self::syncCurrencies();
                $report['tax_rates'] = self::syncTaxRates();
            }
        };

        try {
            DB::transaction(function () use ($run, $dryRun) {
                self::writing($run);
                if ($dryRun) {
                    // Same code path as a real run, so the report is exact; the exception rolls it back.
                    throw new CatalogDryRun;
                }
            });
        } catch (CatalogDryRun) {
        }

        return $report;
    }

    /**
     * @param  class-string<Court|CourtType|HearingType>  $model
     * @param  array<string, array<string, mixed>>  $entries
     * @param  Closure(array<string,mixed>):array<string,mixed>  $extra  more attributes to keep in line (e.g. a court's type)
     * @return array{created:int, adopted:int, updated:int, unchanged:int}
     */
    private static function syncTable(string $model, array $entries, Closure $extra): array
    {
        $counts = ['created' => 0, 'adopted' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($entries as $key => $entry) {
            $want = ['name' => $entry['name'], 'archive' => empty($entry['retired']) ? 0 : 1] + $extra($entry);

            /** @var Court|CourtType|HearingType|null $row */
            $row = $model::withTrashed()->where('catalog_key', $key)->first();
            $adopting = false;

            if (! $row) {
                // A firm row with the same name becomes the system row rather than a duplicate beside it:
                // its id (and everything pointing at it) is kept. Prefer a live row, then the oldest.
                $row = $model::withTrashed()
                    ->whereNull('catalog_key')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($entry['name'])])
                    ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                    ->orderBy('id')
                    ->first();
                $adopting = $row !== null;
            }

            if (! $row) {
                $row = new $model;
                $row->forceFill($want + ['catalog_key' => $key, 'is_system' => true, 'status' => 1]);
                $row->save();
                $counts['created']++;

                continue;
            }

            $row->forceFill($want + ['catalog_key' => $key, 'is_system' => true]);
            $restore = $row->trashed();
            if ($restore) {
                $row->forceFill(['deleted_at' => null, 'deleted_by' => null]);
            }

            if ($row->isDirty()) {
                $row->save();
                $counts[$adopting ? 'adopted' : 'updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    /** @return array{created:int, adopted:int, updated:int, unchanged:int} */
    private static function syncCurrencies(): array
    {
        $counts = ['created' => 0, 'adopted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (self::CURRENCIES as $code => $entry) {
            $want = ['name' => $entry['name'], 'minor_units' => $entry['minor_units'], 'symbol' => $entry['symbol'], 'is_system' => true];
            $row = Currency::where('code', $code)->first();
            if (! $row) {
                // new to this firm: off until it chooses to invoice in it (its base currency is always available)
                Currency::forceCreate($want + ['code' => $code, 'enabled' => false]);
                $counts['created']++;

                continue;
            }
            $adopting = ! $row->is_system;
            $row->forceFill($want);
            if ($row->isDirty()) {
                $row->save();
                $counts[$adopting ? 'adopted' : 'updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    /** @return array<string, array{name: string, rate: int|float, jurisdiction: string, default?: bool, retired?: bool}> */
    private static function taxRateEntries(): array
    {
        return self::TAX_RATES;   // typed as the shape any entry may have, not just today's entries
    }

    /** @return array{created:int, adopted:int, updated:int, unchanged:int} */
    private static function syncTaxRates(): array
    {
        $counts = ['created' => 0, 'adopted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (self::taxRateEntries() as $key => $entry) {
            $want = ['name' => $entry['name'], 'rate' => $entry['rate'], 'jurisdiction' => $entry['jurisdiction'], 'catalog_key' => $key, 'is_system' => true];
            $row = TaxRate::where('catalog_key', $key)->first();
            $adopting = false;
            if (! $row) {
                // a firm's own rate with the same name and jurisdiction becomes the system row (lines keep pointing at it)
                $row = TaxRate::whereNull('catalog_key')->whereRaw('LOWER(name) = ?', [mb_strtolower($entry['name'])])
                    ->where('jurisdiction', $entry['jurisdiction'])->orderBy('id')->first();
                $adopting = $row !== null;
            }
            if (! $row) {
                TaxRate::forceCreate($want + ['is_default' => ! empty($entry['default']) && ! TaxRate::where('is_default', true)->exists(),
                    'archived_at' => empty($entry['retired']) ? null : now()]);
                $counts['created']++;

                continue;
            }
            $row->forceFill($want + (empty($entry['retired']) ? [] : ['archived_at' => $row->archived_at ?? now(), 'is_default' => false]));
            if ($row->isDirty()) {
                $row->save();
                $counts[$adopting ? 'adopted' : 'updated']++;
            } else {
                $counts['unchanged']++;
            }
        }

        return $counts;
    }

    private static function writing(Closure $fn): void
    {
        $previous = self::$syncing;
        self::$syncing = true;
        try {
            $fn();
        } finally {
            self::$syncing = $previous;
        }
    }
}
