<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantStorageCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TEN-12: moves documents stored before per-firm prefixes (e.g. `cases/docs/x.pdf`) to `tenants/<firm>/cases/docs/x.pdf`.
 *
 * Each file is copied, the copy is read back and its SHA-256 compared with the recorded checksum (or, for documents
 * that predate checksum-on-write, with the source's own hash, which is then recorded). Only then is the row switched
 * and the old object deleted. A damaged source, a damaged copy, or an existing different file at the target stops that
 * document and leaves it exactly as it was. Re-running is safe: a run interrupted after the copy picks up where it left
 * off. Run it before setting TENANCY_STORAGE_ROLE_ARN: once firms are confined to their prefix, files outside it are
 * unreachable. (The move itself uses the platform credentials.)
 */
class DocumentsRelocate extends Command
{
    protected $signature = 'documents:relocate
        {--tenant= : Only this firm (multi mode)}
        {--dry-run : Report what would move, change nothing}
        {--keep-old : Leave the old objects in place after a verified move}';

    protected $description = 'Move pre-TEN-12 documents under their firm prefix, verifying every file by checksum';

    /** @var array{moved:int, already:int, missing:int, failed:int} */
    private array $counts = ['moved' => 0, 'already' => 0, 'missing' => 0, 'failed' => 0];

    public function handle(TenantManager $tenants): int
    {
        $this->counts = ['moved' => 0, 'already' => 0, 'missing' => 0, 'failed' => 0];
        if ($tenants->isMulti()) {
            $q = Tenant::whereNotIn('status', ['offboarded', 'purged'])->orderBy('slug');
            if ($slug = $this->option('tenant')) {
                $q->where('slug', $slug);
            }
            foreach ($q->get() as $tenant) {
                $tenants->runIn($tenant, fn () => $this->relocateCurrentFirm($tenant->slug), allowInactive: true);
            }
        } else {
            $this->relocateCurrentFirm(app(TenantContext::class)->key());
        }

        $c = $this->counts;
        $verb = $this->option('dry-run') ? 'to move' : 'moved';
        $this->line("{$c['moved']} {$verb}, {$c['already']} already in place, {$c['missing']} missing, {$c['failed']} failed.");

        return $c['failed'] ? self::FAILURE : self::SUCCESS;
    }

    private function relocateCurrentFirm(string $firm): void
    {
        // the files outside the prefix are exactly what scoped credentials cannot reach
        app(TenantStorageCredentials::class)->apply('default');
        $prefix = app(TenantContext::class)->storagePrefix().'/';

        // compared in PHP, not with LIKE: `_` in a firm key is a LIKE wildcard
        DB::table('documents')->whereNotNull('full_path')->orderBy('id')
            ->chunkById(200, function ($docs) use ($firm, $prefix) {
                foreach ($docs as $doc) {
                    if (! str_starts_with((string) $doc->full_path, $prefix)) {
                        $this->relocate($firm, $prefix, $doc);
                    }
                }
            });
    }

    private function relocate(string $firm, string $prefix, object $doc): void
    {
        $disk = Storage::disk($doc->disk ?: 'local');
        $source = (string) $doc->full_path;
        $target = $prefix.ltrim($source, '/');
        $label = "{$firm} #{$doc->id} {$source}";

        $sourceExists = $disk->exists($source);
        $targetExists = $disk->exists($target);
        if (! $sourceExists && ! $targetExists) {
            $this->warn("{$label}: file missing, left as is");
            $this->counts['missing']++;

            return;
        }

        $expected = $doc->checksum ?: null;
        if ($sourceExists) {
            $sourceHash = $this->hash($disk, $source);
            if ($expected !== null && ! hash_equals($expected, $sourceHash)) {
                $this->error("{$label}: does not match its recorded checksum; not moved");
                $this->counts['failed']++;

                return;
            }
            $expected = $sourceHash;
        }

        if ($this->option('dry-run')) {
            $this->line("{$label} -> {$target}");
            $this->counts['moved']++;

            return;
        }

        if ($targetExists) {
            // an earlier run copied it (or something else lives there): only the same bytes are accepted
            if ($expected === null || ! hash_equals($expected, $this->hash($disk, $target))) {
                $this->error("{$label}: a different file already exists at {$target}; not moved");
                $this->counts['failed']++;

                return;
            }
        } else {
            $in = $disk->readStream($source);
            $disk->writeStream($target, $in);
            is_resource($in) && fclose($in);
            if (! hash_equals((string) $expected, $this->hash($disk, $target))) {
                $disk->delete($target);
                $this->error("{$label}: copy did not verify; not moved");
                $this->counts['failed']++;

                return;
            }
        }

        // the row is switched without touching updated_at or writing audit rows: the document itself did not change
        $filepath = (string) $doc->filepath;
        DB::table('documents')->where('id', $doc->id)->update([
            'full_path' => $target,
            'filepath' => $filepath === '' || $filepath === $source ? $target : (str_starts_with($filepath, $prefix) ? $filepath : $prefix.ltrim($filepath, '/')),
            'checksum' => $expected,
        ]);

        if ($sourceExists && ! $this->option('keep-old')) {
            $disk->delete($source);
        }
        $this->counts[$sourceExists ? 'moved' : 'already']++;
        $this->line("{$label} -> {$target}");
    }

    private function hash(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): string
    {
        $in = $disk->readStream($path);
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $in);
        is_resource($in) && fclose($in);

        return hash_final($ctx);
    }
}
