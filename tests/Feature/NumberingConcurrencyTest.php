<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DATA-8 acceptance: N parallel creates produce N distinct, sequential numbers
 * with zero errors. Needs a real multi-connection database (row locks), so it
 * runs only on MySQL/MariaDB and is skipped on SQLite:
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=... php artisan test --filter=NumberingConcurrencyTest
 */
class NumberingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // No wrapping transaction: the worker processes must see committed data.
    protected array $connectionsToTransact = [];

    private const WORKERS = 6;

    private const PER_WORKER = 10;

    protected function setUp(): void
    {
        // Decide before booting anything: on SQLite the base setUp would try
        // to seed permissions into a schema this test never migrated.
        $driver = $_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: 'sqlite';
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Concurrency test needs MySQL/MariaDB (row-level locks).');
        }

        parent::setUp();

        // Start every test from an empty, committed schema.
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('law:setup-permissions');
    }

    protected function tearDown(): void
    {
        // Workers commit for real, so leave the schema empty for later tests.
        if ($this->app && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    /** @return array{0: array<int,string>, 1: array<int,string>} numbers, errors */
    private function race(string $kind, ?int $invoiceId = null): array
    {
        $case = Cases::factory()->create();
        $conn = config('database.connections.'.DB::getDefaultConnection());
        $env = array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $conn['host'], 'DB_PORT' => (string) $conn['port'],
            'DB_DATABASE' => $conn['database'], 'DB_USERNAME' => $conn['username'], 'DB_PASSWORD' => (string) $conn['password'],
        ]);

        $procs = [];
        for ($w = 0; $w < self::WORKERS; $w++) {
            $p = new Process(
                [PHP_BINARY, base_path('tests/Support/numbering_worker.php'), $kind, (string) self::PER_WORKER, (string) $case->id, (string) $case->client_id, (string) $invoiceId],
                base_path(), $env, null, 120
            );
            $p->start();
            $procs[] = $p;
        }

        $numbers = $errors = [];
        foreach ($procs as $p) {
            $p->wait();
            foreach (explode("\n", trim($p->getOutput())) as $line) {
                if (str_starts_with($line, 'OK ')) {
                    $numbers[] = substr($line, 3);
                } elseif ($line !== '') {
                    $errors[] = $line;
                }
            }
            if ($p->getExitCode() !== 0) {
                $errors[] = 'worker exit '.$p->getExitCode().': '.$p->getErrorOutput();
            }
        }

        return [$numbers, $errors];
    }

    private function assertGapFreeSeries(array $numbers, array $errors, string $prefix): void
    {
        $total = self::WORKERS * self::PER_WORKER;

        $this->assertSame([], $errors, 'parallel creates must not error');
        $this->assertCount($total, $numbers);
        $this->assertCount($total, array_unique($numbers), 'numbers must be distinct');

        sort($numbers);
        $expected = array_map(fn ($n) => $prefix.'-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), range(1, $total));
        $this->assertSame($expected, $numbers, 'numbers must be sequential with no gaps');
    }

    public function test_parallel_invoice_creation_is_distinct_sequential_and_error_free(): void
    {
        [$numbers, $errors] = $this->race('invoice');

        $this->assertGapFreeSeries($numbers, $errors, 'INV');
        $this->assertSame(self::WORKERS * self::PER_WORKER, Invoice::count());
    }

    public function test_parallel_receipt_creation_is_distinct_sequential_and_error_free(): void
    {
        $case = Cases::factory()->create();
        $invoice = Invoice::create([
            'case_id' => $case->id, 'client_id' => $case->client_id,
            'invoice_date' => now()->format('Y-m-d'), 'invoice_due_date' => now()->format('Y-m-d'),
            'workflow_status' => 'draft',
        ]);
        DB::table('invoices')->where('id', $invoice->id)->update(['workflow_status' => 'submitted']);

        [$numbers, $errors] = $this->race('payment', $invoice->id);

        $this->assertGapFreeSeries($numbers, $errors, 'RCT');
    }
}
