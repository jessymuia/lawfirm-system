<?php

namespace App\Releases;

/**
 * PLAT-10 / ENG-18: turns conventional-commit subjects (`feat(scope)!: text`) into draft changelog entries and a suggested bump.
 * feat -> Added; fix -> Fixed (`fix(security)` -> Security); perf/refactor/revert -> Changed; `!` or "BREAKING CHANGE" -> Breaking;
 * docs/test/chore/ci/build/style are not customer-facing and are skipped.
 */
class ConventionalCommits
{
    private const RE = '/^(?<type>feat|fix|perf|refactor|revert|docs|test|chore|ci|build|style)(?:\((?<scope>[^)]+)\))?(?<bang>!)?:\s+(?<text>.+)$/';

    /** @return array{sections:array<string,string[]>,bump:string,skipped:int,unparsed:string[]} */
    public function draft(string $log): array
    {
        $sections = [];
        $skipped = 0;
        $unparsed = [];
        $bump = 'none';
        $rank = ['none' => 0, 'patch' => 1, 'minor' => 2, 'major' => 3];
        $up = function (string $b) use (&$bump, $rank) {
            if ($rank[$b] > $rank[$bump]) {
                $bump = $b;
            }
        };
        foreach (preg_split('/\R/', trim($log)) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(Merge |Release )/', $line)) {
                continue;
            }
            if (preg_match('/^BREAKING[ -]CHANGE:\s*(.+)$/', $line, $b)) {   // a footer line from a commit body
                $sections['Breaking'][] = $b[1];
                $up('major');

                continue;
            }
            if (! preg_match(self::RE, $line, $m)) {
                $unparsed[] = $line;

                continue;
            }
            $text = ucfirst(rtrim($m['text'], '.')).($m['scope'] !== '' ? " ({$m['scope']})" : '');
            if ($m['bang'] === '!') {
                $sections['Breaking'][] = $text;
                $up('major');

                continue;
            }
            switch ($m['type']) {
                case 'feat':
                    $sections['Added'][] = $text;
                    $up('minor');
                    break;
                case 'fix':
                    $sections[$m['scope'] === 'security' ? 'Security' : 'Fixed'][] = $text;
                    $up('patch');
                    break;
                case 'perf':
                case 'refactor':
                case 'revert':
                    $sections['Changed'][] = $text;
                    $up('patch');
                    break;
                default:
                    $skipped++;
            }
        }
        $order = ['Breaking', 'Added', 'Changed', 'Fixed', 'Security'];
        uksort($sections, fn ($a, $b) => array_search($a, $order) <=> array_search($b, $order));

        return ['sections' => $sections, 'bump' => $bump, 'skipped' => $skipped, 'unparsed' => $unparsed];
    }

    public function markdown(array $draft): string
    {
        $out = '';
        foreach ($draft['sections'] as $name => $items) {
            $out .= "### {$name}\n".implode("\n", array_map(fn ($i) => "- {$i}", $items))."\n\n";
        }

        return rtrim($out)."\n";
    }
}
