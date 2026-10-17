<?php

namespace App\Portability;

/** PLAT-9: a bundle on disk (directory, or a .zip that is unpacked to a temp dir). Reading never trusts it: verify() first. */
class Bundle
{
    public array $manifest = [];

    private function __construct(public readonly string $dir, private bool $temporary = false)
    {
    }

    public static function open(string $path): self
    {
        if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
            $tmp = sys_get_temp_dir().'/bundle-'.bin2hex(random_bytes(6));
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                throw new \RuntimeException("Cannot open zip: {$path}");
            }
            // zip-slip guard: refuse any entry that climbs out of the target
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new \RuntimeException("Unsafe path in zip: {$name}");
                }
            }
            mkdir($tmp, 0700, true);
            $zip->extractTo($tmp);
            $zip->close();
            $self = new self($tmp, true);
        } elseif (is_dir($path)) {
            $self = new self(rtrim($path, '/'));
        } else {
            throw new \RuntimeException("Bundle not found: {$path}");
        }
        $mf = $self->dir.'/manifest.json';
        if (! is_file($mf)) {
            $self->close();
            throw new \RuntimeException('manifest.json is missing: this is not a bundle.');
        }
        $self->manifest = json_decode((string) file_get_contents($mf), true) ?: [];

        return $self;
    }

    public function close(): void
    {
        if ($this->temporary && is_dir($this->dir)) {
            self::rmrf($this->dir);
        }
    }

    public static function zip(string $dir, string $zipPath): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create {$zipPath}");
        }
        $base = rtrim($dir, '/');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($base) + 1));
            }
        }
        $zip->close();
    }

    public static function rmrf(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** @return list<string> every problem found; empty means the bundle is intact and readable by this release */
    public function verify(): array
    {
        $p = [];
        $m = $this->manifest;
        if ($why = Format::incompatibility($m['format'] ?? null, $m['version'] ?? null)) {
            return [$why];
        }

        $seal = $this->dir.'/manifest.sha256';
        $expected = is_file($seal) ? strtok((string) file_get_contents($seal), ' ') : null;
        if (! $expected) {
            $p[] = 'manifest.sha256 is missing.';
        } elseif (! hash_equals($expected, hash_file('sha256', $this->dir.'/manifest.json'))) {
            $p[] = 'manifest.json does not match its seal (manifest.sha256): the bundle was altered.';
        }

        foreach ((array) ($m['tables'] ?? []) as $table => $t) {
            $file = $this->safe($t['file'] ?? '');
            if (! $file || ! is_file($file)) {
                $p[] = "{$table}: data file missing.";

                continue;
            }
            if (! hash_equals((string) $t['sha256'], hash_file('sha256', $file))) {
                $p[] = "{$table}: data file checksum mismatch.";

                continue;
            }
            $hash = new RowHash;
            foreach ($this->rows($table) as $row) {
                $hash->add($row);
            }
            if ($hash->count() !== (int) $t['rows'] || $hash->result() !== $t['row_checksum']) {
                $p[] = "{$table}: rows do not match the manifest (count/checksum).";
            }
        }

        if (! empty($m['files']['included'])) {
            $index = $this->safe($m['files']['index'] ?? '');
            if (! $index || ! is_file($index) || ! hash_equals((string) $m['files']['sha256'], hash_file('sha256', $index))) {
                $p[] = 'files/index.ndjson is missing or altered.';
            } else {
                foreach ($this->fileEntries() as $e) {
                    if (! empty($e['missing'])) {
                        continue;
                    }
                    $f = $this->safe($e['bundle_path'] ?? '');
                    if (! $f || ! is_file($f) || ! hash_equals((string) $e['sha256'], hash_file('sha256', $f))) {
                        $p[] = "document #{$e['document_id']}: file missing or checksum mismatch.";
                    }
                }
            }
        }

        return $p;
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function rows(string $table): \Generator
    {
        $h = fopen($this->safe($this->manifest['tables'][$table]['file']), 'rb');
        while (($line = fgets($h)) !== false) {
            if (trim($line) !== '') {
                yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        fclose($h);
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function fileEntries(): \Generator
    {
        $idx = $this->safe($this->manifest['files']['index'] ?? '');
        if (! $idx || ! is_file($idx)) {
            return;
        }
        $h = fopen($idx, 'rb');
        while (($line = fgets($h)) !== false) {
            if (trim($line) !== '') {
                yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        fclose($h);
    }

    public function path(string $relative): ?string
    {
        return $this->safe($relative);
    }

    /** Only paths inside the bundle, never `..` or absolute: the manifest is untrusted input. */
    private function safe(string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || str_contains($relative, "\0")) {
            return null;
        }

        return $this->dir.'/'.$relative;
    }
}
