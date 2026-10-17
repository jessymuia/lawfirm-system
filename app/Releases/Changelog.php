<?php

namespace App\Releases;

/**
 * PLAT-10: reads CHANGELOG.md ("Keep a Changelog" layout) into structured releases.
 *
 *   ## [Unreleased]            or   ## [1.2.0] - 2026-10-01
 *   ### Added | Changed | Fixed | Security | Deprecated | Removed | Breaking
 *   - an entry (indented lines continue it)
 *
 * Bullets before any "###" heading go under "Highlights"; plain prose under a version heading is its summary.
 * "Breaking" means: an entry under a "### Breaking" heading, or one that starts with "BREAKING".
 */
class Changelog
{
    /** Released before dates were required; the date is not known. New releases always get one (scripts/release.sh writes it). */
    public const UNDATED_GRANDFATHERED = ['1.0.0-rc.1'];

    /** @var string[] problems found while reading (the release:check gate reports them) */
    public array $errors = [];

    /** @return array<int,array{version:string,date:?string,unreleased:bool,summary:string,sections:array<string,string[]>,breaking:string[]}> in file order */
    public function parse(string $markdown): array
    {
        $this->errors = [];
        $releases = [];
        $cur = null;
        $section = null;
        $lastItem = null;
        $seen = [];

        foreach (preg_split('/\R/', $markdown) as $n => $line) {
            if (preg_match('/^## \[([^\]]+)\](?:\s+-\s+(\S+))?\s*$/', $line, $m)) {
                $this->finish($cur, $releases);
                $cur = ['version' => $m[1], 'date' => $m[2] ?? null, 'unreleased' => strcasecmp($m[1], 'Unreleased') === 0, 'summary' => '', 'sections' => [], 'breaking' => []];
                $section = $lastItem = null;
                $line1 = $n + 1;
                if (! $cur['unreleased']) {
                    if (! Semver::valid($m[1])) {
                        $this->errors[] = "line {$line1}: '{$m[1]}' is not a semantic version.";
                    }
                    if (isset($seen[$m[1]])) {
                        $this->errors[] = "line {$line1}: version {$m[1]} appears twice.";
                    }
                    $seen[$m[1]] = true;
                    if ($cur['date'] === null && in_array($m[1], self::UNDATED_GRANDFATHERED, true)) {
                        // grandfathered
                    } elseif ($cur['date'] === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $cur['date']) || ! checkdate((int) substr($cur['date'], 5, 2), (int) substr($cur['date'], 8, 2), (int) substr($cur['date'], 0, 4))) {
                        $this->errors[] = "line {$line1}: release {$m[1]} needs a valid date (## [{$m[1]}] - YYYY-MM-DD).";
                    }
                } elseif ($cur['date'] !== null) {
                    $this->errors[] = "line {$line1}: [Unreleased] must not have a date.";
                }

                continue;
            }
            if ($cur === null) {
                continue;
            }
            if (preg_match('/^### (.+?)\s*$/', $line, $m)) {
                $section = $m[1];
                $cur['sections'][$section] ??= [];
                $lastItem = null;

                continue;
            }
            if (preg_match('/^[-*] (.*)$/', $line, $m)) {
                $section ??= 'Highlights';
                $cur['sections'][$section][] = $m[1];
                $lastItem = array_key_last($cur['sections'][$section]);

                continue;
            }
            if ($lastItem !== null && preg_match('/^\s{2,}\S/', $line)) {
                $cur['sections'][$section][$lastItem] .= ' '.trim($line);

                continue;
            }
            if (trim($line) === '') {
                $lastItem = null;

                continue;
            }
            if ($section === null) {
                $cur['summary'] .= ($cur['summary'] === '' ? '' : ' ').trim($line);
            }
        }
        $this->finish($cur, $releases);

        return $releases;
    }

    private function finish(?array $cur, array &$releases): void
    {
        if ($cur === null) {
            return;
        }
        foreach ($cur['sections'] as $name => $items) {
            foreach ($items as $item) {
                $isBreaking = preg_match('/^breaking(?: changes?)?$/i', $name) || preg_match('/^\W*breaking\b/i', $item);
                $isBreaking && $cur['breaking'][] = $item;
            }
        }
        if (! $cur['unreleased'] && $cur['sections'] === [] && $cur['summary'] === '') {
            $this->errors[] = "release {$cur['version']} has no notes.";
        }
        $releases[] = $cur;
    }
}
