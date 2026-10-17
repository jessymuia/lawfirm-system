<?php

namespace App\Audit;

use App\Entitlements\Entitlements;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * DATA-11: moves months of the audit trail older than the firm's online window (plan entitlement
 * `audit_online_months`) out of the `audits` table into one gzipped JSON-lines file per month, and deletes archive
 * files once they are past retention (never below the professional-rules floor, never during a legal hold).
 *
 * Archiving a month: write the file → read it back and check its SHA-256 and row count → record it in
 * `audit_archives` → remove the rows (drop the month's partition when the table is partitioned, else delete by id).
 * A run that stops half-way is finished by the next one; rows are only ever removed once they are in a verified file.
 */
class AuditArchiver
{
    public function __construct(private AuditPartitions $partitions, private Entitlements $entitlements)
    {
    }

    /** null = keep everything online. */
    public function onlineMonths(): ?int
    {
        return $this->entitlements->limit('audit_online_months');
    }

    public function retentionYears(): int
    {
        return max((int) config('audit_retention.floor_years'), (int) config('audit_retention.archive_years'));
    }

    /** Months (YYYY-MM) that are due for archiving, oldest first. @return list<string> */
    public function dueMonths(): array
    {
        $online = $this->onlineMonths();
        if ($online === null) {
            return [];
        }
        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, $online) - 1);   // the current month always stays online
        $oldest = DB::table(AuditPartitions::TABLE)->where('created_at', '<', $cutoff)->min('created_at');
        if ($oldest === null) {
            return [];
        }
        $months = [];
        for ($m = CarbonImmutable::parse($oldest)->startOfMonth(); $m < $cutoff; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /** @return array{month:string, rows:int, removed:int, path:string, how:string} */
    public function archive(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month) ?: throw new RuntimeException("Not a month: {$month}");
        $end = $start->addMonth();
        $existing = DB::table('audit_archives')->where('month', $month)->first();

        if ($existing) {
            // finish an interrupted run: only rows that are in the verified file are removed
            $removed = $this->deleteRows($start, $end, (int) $existing->last_id);

            return ['month' => $month, 'rows' => (int) $existing->rows, 'removed' => $removed, 'path' => $existing->path, 'how' => 'resumed'];
        }

        $disk = (string) (config('audit_retention.disk') ?: \App\Tenancy\FirmStorage::disk());
        $path = app(TenantContext::class)->storagePrefix("audit-archive/{$month}.jsonl.gz");
        $tmp = tempnam(sys_get_temp_dir(), 'audit-');
        $gz = gzopen($tmp, 'wb6');
        $rows = 0;
        $first = $last = null;
        $this->monthQuery($start, $end)->orderBy('id')->chunkById((int) config('audit_retention.chunk'), function ($chunk) use ($gz, &$rows, &$first, &$last) {
            foreach ($chunk as $row) {
                gzwrite($gz, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                $rows++;
                $first ??= (int) $row->id;
                $last = (int) $row->id;
            }
        });
        gzclose($gz);

        try {
            $sha = hash_file('sha256', $tmp);
            $bytes = (int) filesize($tmp);
            $in = fopen($tmp, 'rb');
            Storage::disk($disk)->writeStream($path, $in);
            is_resource($in) && fclose($in);
        } finally {
            @unlink($tmp);
        }

        $check = $this->readBack($disk, $path);
        if ($check['sha256'] !== $sha || $check['rows'] !== $rows) {
            Storage::disk($disk)->delete($path);
            throw new RuntimeException("The archive of {$month} did not verify after writing; nothing was removed.");
        }

        DB::table('audit_archives')->insert([
            'month' => $month, 'rows' => $rows, 'first_id' => $first, 'last_id' => $last, 'disk' => $disk, 'path' => $path,
            'sha256' => $sha, 'bytes' => $bytes, 'archived_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $how = 'rows';
        if ($rows > 0 && $this->partitions->dropMonth($start, $rows)) {
            $removed = $rows;
            $how = 'partition';
        } else {
            $removed = $this->deleteRows($start, $end, (int) $last);
        }

        return ['month' => $month, 'rows' => $rows, 'removed' => $removed, 'path' => $path, 'how' => $how];
    }

    /** Delete archive files past retention. @return list<string> the months pruned */
    public function prune(): array
    {
        if (DB::table('legal_holds')->whereNull('released_at')->exists()) {
            return [];   // the firm has an active legal hold: keep every record until it is released
        }
        $before = CarbonImmutable::now()->startOfMonth()->subYears($this->retentionYears());
        $pruned = [];
        foreach (DB::table('audit_archives')->whereNull('pruned_at')->where('month', '<', $before->format('Y-m'))->orderBy('month')->get() as $a) {
            Storage::disk($a->disk)->delete($a->path);
            DB::table('audit_archives')->where('id', $a->id)->update(['pruned_at' => now(), 'updated_at' => now()]);
            $pruned[] = $a->month;
        }

        return $pruned;
    }

    /** Put an archived month back into the live table (e.g. for an investigation). Rows already present are kept. */
    public function restore(string $month): int
    {
        $a = DB::table('audit_archives')->where('month', $month)->first() ?? throw new RuntimeException("No archive for {$month}.");
        if ($a->pruned_at) {
            throw new RuntimeException("The archive of {$month} was deleted at the end of retention ({$a->pruned_at}).");
        }
        $check = $this->readBack($a->disk, $a->path);
        if ($check['sha256'] !== $a->sha256) {
            throw new RuntimeException("The archive of {$month} does not match its recorded SHA-256; not restored.");
        }

        $restored = 0;
        foreach (array_chunk($check['lines'], (int) config('audit_retention.chunk')) as $batch) {
            $rows = array_map(fn ($l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR), $batch);
            $present = DB::table(AuditPartitions::TABLE)->whereIn('id', array_column($rows, 'id'))->pluck('id')->flip();
            $missing = array_values(array_filter($rows, fn ($r) => ! isset($present[$r['id']])));
            if ($missing) {
                DB::table(AuditPartitions::TABLE)->insert($missing);
                $restored += count($missing);
            }
        }

        return $restored;
    }

    private function monthQuery(CarbonImmutable $start, CarbonImmutable $end): \Illuminate\Database\Query\Builder
    {
        return DB::table(AuditPartitions::TABLE)->where('created_at', '>=', $start)->where('created_at', '<', $end);
    }

    private function deleteRows(CarbonImmutable $start, CarbonImmutable $end, int $lastArchivedId): int
    {
        $removed = 0;
        do {
            $ids = $this->monthQuery($start, $end)->where('id', '<=', $lastArchivedId)->orderBy('id')->limit((int) config('audit_retention.chunk'))->pluck('id');
            $removed += $ids->isEmpty() ? 0 : DB::table(AuditPartitions::TABLE)->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());

        return $removed;
    }

    /** @return array{sha256:string, rows:int, lines:list<string>} */
    private function readBack(string $disk, string $path): array
    {
        $raw = Storage::disk($disk)->get($path) ?? throw new RuntimeException("Archive file {$path} is missing.");
        $sha = hash('sha256', $raw);
        $lines = array_values(array_filter(explode("\n", (string) gzdecode($raw)), fn ($l) => $l !== ''));

        return ['sha256' => $sha, 'rows' => count($lines), 'lines' => $lines];
    }
}
