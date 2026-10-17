<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as CommandAlias;

class MigrationExportDocumentChecksums extends Command
{
    /**
     * §6 Phase 1 (Export): produces a SHA-256 checksum manifest of every
     * document file, keyed by document id, alongside the export. §6
     * Phase 3's verification (see tests/Migration/MigrationVerificationTest.php)
     * re-hashes each file after migration and compares against this
     * manifest — proving the *bytes* migrated correctly, not just that a
     * `documents` row with the right id exists.
     *
     * Run this in the OLD system before export, then again in the NEW
     * system after Phase 2's file copy, pointing at the same manifest
     * path both times (the second run is --verify mode: compares
     * instead of overwriting).
     *
     * @var string
     */
    protected $signature = 'migration:document-checksums
                            {--manifest= : Path to write/read the checksum manifest JSON}
                            {--verify : Compare current files against an existing manifest instead of writing a new one}';

    protected $description = 'Generate or verify a SHA-256 checksum manifest of every document file (§6 Phase 1/3)';

    public function handle(): int
    {
        $manifestPath = $this->option('manifest') ?? config('services.migration.checksum_manifest_path');
        if (! $manifestPath) {
            $this->error('No manifest path given (pass --manifest= or set MIGRATION_CHECKSUM_MANIFEST_PATH).');

            return CommandAlias::FAILURE;
        }

        if ($this->option('verify')) {
            return $this->verify($manifestPath);
        }

        return $this->generate($manifestPath);
    }

    private function generate(string $manifestPath): int
    {
        $manifest = [];
        $missing = 0;

        Document::withTrashed()->chunk(200, function ($documents) use (&$manifest, &$missing) {
            foreach ($documents as $document) {
                $disk = $document->disk ?: 'local';

                if (! Storage::disk($disk)->exists($document->full_path)) {
                    $this->warn("Missing file for document #{$document->id}: {$document->full_path} (disk: {$disk})");
                    $missing++;

                    continue;
                }

                $manifest[$document->id] = [
                    'full_path' => $document->full_path,
                    'disk' => $disk,
                    'sha256' => hash('sha256', Storage::disk($disk)->get($document->full_path)),
                    'filesize' => $document->filesize,
                ];
            }
        });

        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));

        $this->info('Wrote checksums for '.count($manifest)." documents to {$manifestPath}.");
        if ($missing > 0) {
            $this->warn("{$missing} document row(s) had no corresponding file on disk — investigate before migrating.");
        }

        return CommandAlias::SUCCESS;
    }

    private function verify(string $manifestPath): int
    {
        if (! file_exists($manifestPath)) {
            $this->error("Manifest not found at {$manifestPath}.");

            return CommandAlias::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $mismatches = 0;
        $missing = 0;

        foreach ($manifest as $documentId => $entry) {
            $disk = $entry['disk'] ?? 'local';

            if (! Storage::disk($disk)->exists($entry['full_path'])) {
                $this->error("Document #{$documentId}: file missing after migration ({$entry['full_path']}).");
                $missing++;

                continue;
            }

            $actualHash = hash('sha256', Storage::disk($disk)->get($entry['full_path']));
            if ($actualHash !== $entry['sha256']) {
                $this->error("Document #{$documentId}: checksum mismatch (expected {$entry['sha256']}, got {$actualHash}).");
                $mismatches++;
            }
        }

        if ($mismatches === 0 && $missing === 0) {
            $this->info('All '.count($manifest).' document checksums match.');

            return CommandAlias::SUCCESS;
        }

        $this->error("{$mismatches} checksum mismatch(es), {$missing} missing file(s) out of ".count($manifest).' documents.');

        return CommandAlias::FAILURE;
    }
}
