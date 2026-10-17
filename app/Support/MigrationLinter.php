<?php

namespace App\Support;

/**
 * DATA-13: static check that flags destructive or table-rewriting operations
 * in a migration's up().
 *
 * Why: the SaaS deploys continuously while self-hosted installs lag by months,
 * so a release's schema must still work for the previous release's code
 * (expand/contract), and a migration that rewrites a big table locks big
 * tenants out. See docs/MIGRATION_POLICY.md.
 *
 * A finding can be accepted explicitly, with a reason, anywhere in the file:
 *     // migration-lint: allow data-rewrite -- one-off, table has < 1k rows by design
 * The whole file is inspected (helpers called from up() count) except down(),
 * which is expected to drop things.
 */
class MigrationLinter
{
    /** rule => [regex, explanation] */
    public const RULES = [
        'drop-column' => ['/->\s*dropColumns?\s*\(|->\s*dropSoftDeletes\s*\(|->\s*dropTimestamps\s*\(/i',
            'drops a column: contract step, must ship a release AFTER code stops reading it'],
        'drop-table' => ['/Schema::\s*(dropIfExists|drop)\s*\(/i',
            'drops a table: contract step'],
        'rename' => ['/->\s*renameColumn\s*\(|Schema::\s*rename\s*\(/i',
            'renames break the previous release: add new, dual-write, switch reads, drop old'],
        'change-type' => ['/->\s*change\s*\(\s*\)/',
            'changing a column type rewrites the table and can break the previous release'],
        'drop-constraint' => ['/->\s*drop(Index|Unique|Primary|Foreign|Constrained(ForeignId)?)\s*\(/i',
            'dropping an index/constraint can break invariants the previous release relies on'],
        'data-rewrite' => ['/DB::\s*table\s*\([^;]*?->\s*(update|delete|truncate|increment|decrement)\s*\(|->\s*(each|chunk|chunkById|lazy|cursor)\s*\(|::\s*query\s*\(\s*\)\s*->\s*(update|delete)\s*\(|::\s*(update|truncate)\s*\(|DB::\s*(statement|unprepared|affectingStatement)\s*\(\s*[\'"]\s*(UPDATE|DELETE|TRUNCATE|INSERT\s+INTO\s+\S+\s+SELECT)\b/is',
            'backfills are queued jobs, not migrations (they lock and time out on big tenants)'],
        'raw-alter' => ['/DB::\s*(statement|unprepared)\s*\(\s*[\'"]\s*ALTER\s+TABLE[^\'"]*\b(DROP|MODIFY|CHANGE|RENAME)\b/is',
            'raw ALTER that drops, rewrites or renames'],
    ];

    /** @return array<int, array{rule:string, line:int, message:string, code:string}> */
    public function lint(string $source): array
    {
        $allowed = $this->allowances($source, $problems);
        $up = $this->withoutDown($source);
        $findings = $problems;

        foreach (self::RULES as $rule => [$regex, $message]) {
            if (isset($allowed[$rule]) || isset($allowed['all'])) {
                continue;
            }
            if (preg_match_all($regex, $up['body'], $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$text, $offset]) {
                    $line = $up['line'] + substr_count($up['body'], "\n", 0, $offset);
                    $findings[] = ['rule' => $rule, 'line' => $line, 'message' => $message, 'code' => trim(preg_replace('/\s+/', ' ', $text))];
                }
            }
        }

        usort($findings, fn ($a, $b) => $a['line'] <=> $b['line']);

        return $findings;
    }

    /**
     * @param-out array<int, array{rule:string, line:int, message:string, code:string}> $problems
     * @return array<string,true>
     */
    private function allowances(string $source, ?array &$problems): array
    {
        $problems = [];
        $allowed = [];
        foreach (preg_split('/\R/', $source) as $i => $line) {
            if (! preg_match('#migration-lint:\s*allow\s+([a-z\-]+)(.*)$#i', $line, $m)) {
                continue;
            }
            $rule = strtolower($m[1]);
            $reason = trim(ltrim(trim($m[2]), '-— '));
            if ($rule !== 'all' && ! isset(self::RULES[$rule])) {
                $problems[] = ['rule' => 'bad-allow', 'line' => $i + 1, 'message' => "unknown rule '{$rule}' in allow comment", 'code' => trim($line)];

                continue;
            }
            if (strlen($reason) < 10) {
                $problems[] = ['rule' => 'bad-allow', 'line' => $i + 1, 'message' => 'allow comment needs a reason: "-- why this is safe"', 'code' => trim($line)];

                continue;
            }
            $allowed[$rule] = true;
        }

        return $allowed;
    }

    /**
     * The file with down()'s body blanked out (newlines kept so line numbers hold).
     *
     * @return array{body:string, line:int}
     */
    private function withoutDown(string $source): array
    {
        if (preg_match('/function\s+down\s*\([^)]*\)[^{]*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $len = strlen($source);
            for ($i = $start; $i < $len && $depth > 0; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                }
            }
            $blank = preg_replace('/[^\n]/', ' ', substr($source, $start, $i - $start - 1));
            $source = substr($source, 0, $start).$blank.substr($source, $i - 1);
        }

        return ['body' => $source, 'line' => 1];
    }
}
