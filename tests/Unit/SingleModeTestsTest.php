<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TEN-17: tests excluded from the multi-tenant CI run (#[Group('single-mode')]) must be about self-hosted-only
 * behaviour. The list is kept in tests/single-mode-baseline.txt and may only shrink; a new entry needs a reason there.
 */
class SingleModeTestsTest extends TestCase
{
    public function test_the_single_mode_group_only_shrinks(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__))) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'SingleModeTestsTest.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (! str_contains($src, "Group('single-mode')")) {
                continue;
            }
            $class = basename($file->getPathname(), '.php');
            if (preg_match("/Group\\('single-mode'\\)[^\\n]*\\n(?:#\\[[^\\n]*\\n)*\\s*(?:final\\s+)?class /", $src)) {
                $found[] = $class;   // the whole class
            }
            preg_match_all("/Group\\('single-mode'\\)[^\\n]*\\n\\s*public function (test\\w+)/", $src, $m);
            foreach ($m[1] as $method) {
                $found[] = "{$class}::{$method}";
            }
        }
        sort($found);

        $baseline = array_values(array_filter(array_map(fn ($l) => trim(explode('#', $l)[0]), file(dirname(__DIR__).'/single-mode-baseline.txt'))));
        sort($baseline);

        $this->assertSame([], array_values(array_diff($found, $baseline)), 'New single-mode tests: make them work in both modes, or add them to tests/single-mode-baseline.txt with the reason.');
        $this->assertSame([], array_values(array_diff($baseline, $found)), 'Tests left the single-mode group: remove them from tests/single-mode-baseline.txt.');
    }
}
