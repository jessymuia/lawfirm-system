<?php

namespace Tests\Isolation;

use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

/** TEN-10: exports: what leaves a firm contains that firm only. */
class ExportIsolationTest extends IsolationTestCase
{
    /** @return array<string,string> entry name => contents of the zip the response carries */
    private function zipEntries($response): array
    {
        $response->assertOk();
        $copy = tempnam(sys_get_temp_dir(), 'iso').'.zip';
        copy($response->baseResponse->getFile()->getPathname(), $copy);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($copy) === true);
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($copy);

        return $out;
    }

    private function enableExport(): void
    {
        Tenant::query()->update(['plan' => 'professional']);
        app(\App\Entitlements\Entitlements::class)->forget();
    }

    public function test_the_full_firm_export_contains_only_that_firm(): void
    {
        $this->enableExport();
        $a = $this->zipEntries($this->api('a', 'GET', '/export/firm-data'));
        $b = $this->zipEntries($this->api('b', 'GET', '/export/firm-data'));

        $this->assertStringContainsString('CANARY-A', $a['clients.csv']);
        $this->assertStringContainsString('CANARY-B', $b['clients.csv']);
        $this->assertStringNotContainsString('CANARY-B', implode("\n", $a), 'A\'s export contains B\'s data');
        $this->assertStringNotContainsString('CANARY-A', implode("\n", $b), 'B\'s export contains A\'s data');
        $this->assertSame(2 + 1, substr_count($a['clients.csv'], "\n"), 'A: header + 2 clients');
        $this->assertSame(5 + 1, substr_count($b['clients.csv'], "\n"), 'B: header + 5 clients');
    }

    public function test_two_firms_exporting_in_the_same_second_get_their_own_zip(): void
    {
        $this->enableExport();
        \Illuminate\Support\Carbon::setTestNow(now()->startOfSecond());
        try {
            $ra = $this->api('a', 'GET', '/export/firm-data')->assertOk();
            $fa = $ra->baseResponse->getFile()->getPathname();
            $rb = $this->api('b', 'GET', '/export/firm-data')->assertOk();
            $fb = $rb->baseResponse->getFile()->getPathname();
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
        $this->assertNotSame($fa, $fb, 'two firms must never be given the same scratch file');
        $this->assertStringContainsString('/tmp-exports/a/', $fa);
        $this->assertStringContainsString('/tmp-exports/b/', $fb);
    }

    public function test_csv_exports_contain_only_that_firm(): void
    {
        foreach (['a' => 'b', 'b' => 'a'] as $mine => $other) {
            foreach (['/export/cases', '/export/invoices', '/export/expenses'] as $uri) {
                $r = $this->api($mine, 'GET', $uri)->assertOk();
                $body = $r instanceof \Illuminate\Testing\TestResponse && $r->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
                    ? $r->streamedContent() : $r->getContent();
                $this->assertStringNotContainsString(strtolower($this->canary($other)), strtolower($body), "{$uri}: {$mine}'s export contains {$other}'s data");
            }
        }
        $own = $this->api('a', 'GET', '/export/cases')->streamedContent();
        $this->assertStringContainsString('CANARY-A-CASE', $own);
    }

    public function test_the_portable_bundle_of_one_firm_contains_nothing_of_the_other(): void
    {
        $target = "{$this->dir}/bundle-a";
        $this->assertSame(0, Artisan::call('portability:export', ['--tenant' => 'a', '--output' => $target]), Artisan::output());

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $f->isFile() && $files[] = $f->getPathname();
        }
        $this->assertNotEmpty($files, 'the export must have produced something to inspect');
        $this->assertStringContainsString('CANARY-A', implode('', array_map('file_get_contents', $files)), 'sanity: the bundle does hold A\'s data');
        foreach ($files as $f) {
            $content = (string) file_get_contents($f);
            $this->assertStringNotContainsString('CANARY-B', $content, basename($f).' contains B\'s data');
        }
    }
}
