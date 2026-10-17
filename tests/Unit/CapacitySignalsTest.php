<?php

namespace Tests\Unit;

use App\Capacity\Signals;
use Tests\TestCase;

/** ENG-27: the scaling signals turn the measured ceilings of docs/ops/CAPACITY.md into OK / WARN / FULL. */
class CapacitySignalsTest extends TestCase
{
    private function cell(array $over = []): array
    {
        return $over + ['firms' => 100, 'capacity' => 300, 'tables' => 8200, 'table_open_cache' => 4000, 'table_definition_cache' => 4000, 'open_files_limit' => 10000,
            'opened_tables' => 3600, 'uptime' => 3600, 'threads_connected' => 20, 'max_used_connections' => 40, 'max_connections' => 500,
            'pool_reads' => 10, 'pool_read_requests' => 100000, 'bytes' => 5 * 1073741824, 'queue' => 3];
    }

    private function states(array $m): array
    {
        return array_column(Signals::evaluate($m), 'state', 'signal');
    }

    public function test_a_healthy_cell_is_all_ok(): void
    {
        $s = $this->states($this->cell());
        unset($s['database size']);
        $this->assertSame(['OK'], array_values(array_unique($s)));
    }

    public function test_each_signal_warns_before_its_ceiling_and_says_what_to_do(): void
    {
        $s = $this->states($this->cell(['firms' => 250]));
        $this->assertSame('WARN', $s['firms in the cell'], '250 of 300 is past 80 %');
        $this->assertSame('FULL', $this->states($this->cell(['firms' => 300]))['firms in the cell']);
        $this->assertSame('WARN', $this->states($this->cell(['tables' => 21000]))['tables on the server']);

        // the table cache must hold every active firm's hot tables: 250 firms x 20 > 4000
        $this->assertSame('WARN', $s['table_open_cache vs firms x hot tables']);
        $row = collect(Signals::evaluate($this->cell(['firms' => 250])))->firstWhere('signal', 'table_open_cache vs firms x hot tables');
        $this->assertStringContainsString('at least 5000', $row['action']);

        $this->assertSame('FULL', $this->states($this->cell(['opened_tables' => 36000]))['tables opened per second'], '10 a second churns');
        $this->assertSame('WARN', $this->states($this->cell(['max_used_connections' => 420]))['connections in use (peak)']);
        $this->assertSame('WARN', $this->states($this->cell(['pool_reads' => 5000]))['buffer pool hit ratio']);
        $this->assertSame('WARN', $this->states($this->cell(['queue' => 450]))['jobs waiting (interactive lane)']);
    }

    public function test_what_a_server_cannot_report_is_not_guessed(): void
    {
        $s = $this->states(['firms' => 1, 'capacity' => null] + array_fill_keys(['tables', 'table_open_cache', 'table_definition_cache', 'open_files_limit',
            'opened_tables', 'uptime', 'threads_connected', 'max_used_connections', 'max_connections', 'pool_reads', 'pool_read_requests', 'bytes', 'queue'], null));
        $this->assertSame('n/a', $s['firms in the cell']);
        $this->assertSame('n/a', $s['buffer pool hit ratio']);
    }
}
