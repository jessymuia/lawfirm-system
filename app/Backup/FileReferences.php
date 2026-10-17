<?php

namespace App\Backup;

use Illuminate\Database\Connection;

/**
 * TEN-14: every stored file a firm's database points at, as (disk, path). A restored database is only usable if each of
 * these exists: a database restored to yesterday pointing at a file deleted today is an incident. Add a row here for
 * any new table that stores a file's location.
 */
class FileReferences
{
    /** table => [disk column, path column] */
    public const COLUMNS = [
        'documents' => ['disk', 'full_path'],
        'matter_emails' => ['raw_disk', 'raw_path'],
        'document_templates' => ['disk', 'path'],
        'audit_archives' => ['disk', 'path'],
    ];

    /** @return list<array{disk: string, path: string, table: string}> */
    public static function in(Connection $db): array
    {
        $out = [];
        $schema = $db->getSchemaBuilder();
        foreach (self::COLUMNS as $table => [$disk, $path]) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            foreach ($db->table($table)->whereNotNull($path)->where($path, '!=', '')->select([$disk.' as d', $path.' as p'])->cursor() as $r) {
                $out[] = ['disk' => (string) ($r->d ?: 'local'), 'path' => (string) $r->p, 'table' => $table];
            }
        }
        // UI-13: the logo is in firm_settings, not a column of its own
        if ($schema->hasTable('firm_settings') && ($b = $db->table('firm_settings')->where('key', 'branding')->value('value'))) {
            $logo = json_decode((string) $b, true)['logo'] ?? null;
            if (is_array($logo) && ! empty($logo['path'])) {
                $out[] = ['disk' => (string) ($logo['disk'] ?? 'local'), 'path' => (string) $logo['path'], 'table' => 'firm_settings'];
            }
        }

        return $out;
    }
}
