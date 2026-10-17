<?php

namespace App\Releases;

/**
 * PLAT-10: what changed between two versions, which upgrade guides apply, and whether the changelog and guides are in order.
 * Upgrade guides live in docs/upgrade/<version>.md (docs/upgrade/unreleased.md for what is not yet released).
 */
class ReleaseNotes
{
    public const REQUIRED_GUIDE_HEADINGS = ['## Before you upgrade', '## Required actions', '## Rollback'];

    private array $releases;

    public array $parseErrors;

    public function __construct(private string $changelogPath, private string $guideDir, private string $versionFile)
    {
        $c = new Changelog();
        $this->releases = is_file($changelogPath) ? $c->parse((string) file_get_contents($changelogPath)) : [];
        $this->parseErrors = $c->errors;
    }

    public static function fromApp(): self
    {
        return new self(base_path('CHANGELOG.md'), base_path('docs/upgrade'), base_path('VERSION'));
    }

    /** Released versions, newest first (the unreleased section only on request). */
    public function releases(bool $withUnreleased = false): array
    {
        $r = array_values(array_filter($this->releases, fn ($x) => ! $x['unreleased'] && Semver::valid($x['version'])));
        usort($r, fn ($a, $b) => Semver::compare($b['version'], $a['version']));
        if ($withUnreleased && ($u = $this->unreleased())) {
            array_unshift($r, $u);
        }

        return $r;
    }

    public function unreleased(): ?array
    {
        return collect($this->releases)->first(fn ($x) => $x['unreleased']);
    }

    public function find(string $version): ?array
    {
        return collect($this->releases(true))->first(fn ($x) => $x['version'] === $version);
    }

    public function guidePath(string $version): string
    {
        // the version has been validated as semver or 'unreleased' by every caller; never build a path from anything else
        return $this->guideDir.'/'.(Semver::valid($version) ? $version : 'unreleased').'.md';
    }

    public function guide(string $version): ?string
    {
        $p = $this->guidePath($version);

        return is_file($p) ? (string) file_get_contents($p) : null;
    }

    /**
     * Releases newer than $from up to and including $to, with the breaking entries and the guides that apply.
     *
     * @return array{from:?string,to:string,boundary:string,releases:array,breaking:array,guides:array<string,string>,missing_guides:string[]}
     */
    public function between(?string $from, string $to, bool $withUnreleased = false): array
    {
        $out = ['from' => $from, 'to' => $to, 'boundary' => 'none', 'releases' => [], 'breaking' => [], 'guides' => [], 'missing_guides' => []];
        if ($from === null || ! Semver::valid($from) || ! Semver::valid($to)) {
            return $out;   // a fresh install has nothing to upgrade from
        }
        $out['boundary'] = Semver::bump($from, $to);
        if ($out['boundary'] === 'downgrade') {
            return $out;
        }
        foreach ($this->releases($withUnreleased) as $r) {
            $inRange = $r['unreleased'] || (Semver::compare($r['version'], $from) > 0 && Semver::compare($r['version'], $to) <= 0);
            if (! $inRange) {
                continue;
            }
            $out['releases'][] = $r;
            foreach ($r['breaking'] as $b) {
                $out['breaking'][] = ['version' => $r['version'], 'entry' => $b];
            }
            if ($this->guide($r['version']) !== null) {
                $out['guides'][$r['version']] = $this->guidePath($r['version']);
            } elseif ($this->needsGuide($r)) {
                $out['missing_guides'][] = $r['version'];
            }
        }

        return $out;
    }

    /** Short lines for `app:upgrade`: never throws, never blocks an upgrade. @return string[] */
    public function summaryLines(?string $from, string $to): array
    {
        try {
            $b = $this->between($from, $to);
            if ($b['boundary'] === 'downgrade') {
                return ['Release notes: this is older than the recorded release; see docs/ops/UPGRADE_ROLLBACK.md.'];
            }
            if ($b['releases'] === []) {
                return [];
            }
            $lines = ['Release notes: '.count($b['releases']).' release(s) between '.$from.' and '.$to.' ('.$b['boundary'].' upgrade).'];
            if ($b['breaking']) {
                $lines[] = count($b['breaking']).' breaking change(s): run `php artisan release:notes` before continuing.';
            }
            foreach ($b['guides'] as $v => $p) {
                $lines[] = "Upgrade guide for {$v}: ".str_replace(base_path().'/', '', $p);
            }
            if (in_array($b['boundary'], ['major', 'minor'], true) && ! $b['breaking'] && ! $b['guides']) {
                $lines[] = 'Read the changelog for the versions in between (`php artisan release:notes`).';
            }

            return $lines;
        } catch (\Throwable) {
            return [];
        }
    }

    public function needsGuide(array $release): bool
    {
        if ($release['breaking']) {
            return true;
        }
        if ($release['unreleased']) {
            return false;
        }
        $older = collect($this->releases())->filter(fn ($x) => Semver::compare($x['version'], $release['version']) < 0)->first();

        return $older !== null && in_array(Semver::bump($older['version'], $release['version']), ['major', 'minor'], true);
    }

    /** The gate behind `release:check`. @return string[] problems; empty = fine */
    public function check(): array
    {
        $e = $this->parseErrors;
        $all = collect($this->releases);
        if ($all->isEmpty()) {
            return ['CHANGELOG.md is missing or has no release sections.'];
        }
        if (! $all->first()['unreleased']) {
            $e[] = 'The first section must be [Unreleased].';
        }
        $released = $this->releases();
        $order = $all->reject(fn ($x) => $x['unreleased'] || ! Semver::valid($x['version']))->pluck('version')->values()->all();
        if ($order !== array_column($released, 'version')) {
            $e[] = 'Releases must be listed newest first.';
        }
        $v = is_file($this->versionFile) ? trim((string) file_get_contents($this->versionFile)) : '';
        if ($released && $v !== $released[0]['version']) {
            $e[] = "VERSION is '{$v}' but the newest released section is {$released[0]['version']}.";
        }

        foreach ($released as $r) {
            foreach ($r['sections'] as $name => $items) {
                foreach ($items as $item) {
                    if (preg_match('/\b(TODO|TBD|FIXME)\b/', $item)) {
                        $e[] = "{$r['version']}: an entry in '{$name}' is unfinished (TODO/TBD).";
                    }
                }
            }
            if ($this->needsGuide($r) && $this->guide($r['version']) === null) {
                $e[] = "{$r['version']} needs an upgrade guide (docs/upgrade/{$r['version']}.md): it has breaking changes or is a minor/major step.";
            }
        }
        if (($u = $this->unreleased()) && $this->needsGuide($u) && $this->guide('unreleased') === null) {
            $e[] = 'Unreleased has breaking changes: write docs/upgrade/unreleased.md (it is renamed at release).';
        }

        $known = array_merge(array_column($released, 'version'), ['unreleased']);
        foreach (glob($this->guideDir.'/*.md') ?: [] as $f) {
            $name = basename($f, '.md');
            if (in_array($name, ['README', 'TEMPLATE'], true)) {
                continue;
            }
            if (! in_array($name, $known, true)) {
                $e[] = "docs/upgrade/{$name}.md belongs to no release in CHANGELOG.md.";

                continue;
            }
            $text = (string) file_get_contents($f);
            foreach (self::REQUIRED_GUIDE_HEADINGS as $h) {
                if (! preg_match('/^'.preg_quote($h, '/').'\s*$/m', $text)) {
                    $e[] = "docs/upgrade/{$name}.md lacks the section '{$h}'.";
                }
            }
            if (preg_match('/\b(TODO|TBD|FIXME)\b/', $text)) {
                $e[] = "docs/upgrade/{$name}.md is unfinished (TODO/TBD).";
            }
        }

        return $e;
    }
}
