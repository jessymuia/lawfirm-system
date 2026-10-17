<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Warehouse\WarehousePurgeStep;
use App\Warehouse\WarehouseSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** ENG-26: daily per-firm aggregates to the warehouse, and nothing a client told the firm. */
class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private string $firm;

    private string $whFile;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\ExtractDocumentText::class]);
        Storage::fake('warehouse-test');
        $this->whFile = sys_get_temp_dir().'/warehouse-'.uniqid().'.sqlite';
        touch($this->whFile);
        config([
            'warehouse.enabled' => true, 'warehouse.sinks' => ['files', 'database'], 'warehouse.disk' => 'warehouse-test',
            'database.connections.warehouse_test' => ['driver' => 'sqlite', 'database' => $this->whFile, 'prefix' => ''],
            'warehouse.connection' => 'warehouse_test',
        ]);
        $this->firm = app(TenantContext::class)->key();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 08:00'));
    }

    protected function tearDown(): void
    {
        @unlink($this->whFile);
        parent::tearDown();
    }

    private function seedDay(): array
    {
        $lawyer = User::factory()->create();
        $client = Client::factory()->create(['name' => 'Secret Client Wambui', 'address' => 'Confidential Road']);
        $matter = Cases::factory()->create(['client_id' => $client->id, 'description' => 'Privileged: client admits the debt', 'assigned_to' => $lawyer->id]);
        Document::create(['title' => 'Without prejudice letter', 'case_id' => $matter->id, 'version' => 1, 'is_current' => true, 'filename' => 'wp.pdf',
            'filepath' => 'x', 'full_path' => 'x', 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 2048, 'extension' => 'pdf']);
        TimeEntry::create(['case_id' => $matter->id, 'user_id' => $lawyer->id, 'description' => 'Call with client about the admission', 'date' => '2026-10-04',
            'hours' => 1.5, 'hourly_rate' => 10000, 'billable' => true]);
        $inv = Invoice::create(['case_id' => $matter->id, 'client_id' => $client->id, 'invoice_date' => '2026-10-04', 'invoice_due_date' => '2026-11-04', 'workflow_status' => 'draft']);
        $inv->items()->create(['description' => 'Advice on the admission', 'quantity' => 1, 'rate' => 30000, 'tax' => 0, 'total_amount' => 30000]);
        $inv->refreshTotals();
        $inv->forceFill(['workflow_status' => 'submitted'])->save();
        Payment::create(['invoice_id' => $inv->id, 'amount' => 12000, 'payment_date' => '2026-10-04', 'method' => 'cash', 'received_by' => $lawyer->id, 'notes' => 'paid by the client\'s brother']);
        DB::table('audits')->insert([['event' => 'login', 'user_type' => User::class, 'user_id' => $lawyer->id, 'auditable_type' => 'x', 'auditable_id' => 1,
            'old_values' => '[]', 'new_values' => '{"name":"Secret Client Wambui"}', 'created_at' => '2026-10-04 09:00:00', 'updated_at' => now()]]);
        DB::table('cases')->update(['created_at' => '2026-10-04 10:00:00']);
        DB::table('clients')->update(['created_at' => '2026-10-04 10:00:00']);
        DB::table('documents')->update(['created_at' => '2026-10-04 11:00:00']);

        return [$lawyer, $client, $matter];
    }

    private function file(string $table, string $date): array
    {
        $raw = gzdecode(Storage::disk('warehouse-test')->get("warehouse/{$table}/dt={$date}/tenant={$this->firm}.ndjson.gz"));

        return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $raw)));
    }

    public function test_yesterday_is_exported_as_aggregates_per_firm(): void
    {
        $this->seedDay();
        $this->assertSame(0, Artisan::call('warehouse:export'));   // yesterday = 2026-10-04

        $daily = $this->file('firm_daily', '2026-10-04')[0];
        $this->assertSame($this->firm, $daily['tenant_key']);
        $this->assertSame([1, 1, 1, 1, 1, 2048, 1.5, 1.5], [$daily['matters_opened'], $daily['clients_added'], $daily['users_active'], $daily['documents_added'],
            $daily['matters_total'], $daily['document_bytes_added'], $daily['hours_logged'], $daily['hours_billable']]);

        $money = $this->file('firm_money_daily', '2026-10-04');
        $this->assertSame([['KES', 1, 30000, 1, 12000, 15000]], array_map(fn ($r) => [$r['currency'], $r['invoices_issued'], $r['invoiced'], $r['payments'],
            $r['collected'], $r['time_value']], $money));
        $this->assertSame([['login', 1]], array_map(fn ($r) => [$r['event'], $r['count']], $this->file('firm_events_daily', '2026-10-04')));
        $this->assertSame('self_hosted', $this->file('dim_firm', '2026-10-04')[0]['plan']);

        // the same, in the warehouse database
        $wh = DB::connection('warehouse_test');
        $this->assertSame(30000.0, (float) $wh->table('wh_firm_money_daily')->where('tenant_key', $this->firm)->value('invoiced'));
    }

    public function test_nothing_a_client_told_the_firm_reaches_the_warehouse(): void
    {
        $this->seedDay();
        Artisan::call('warehouse:export', ['--date' => '2026-10-04']);

        $everything = '';
        foreach (Storage::disk('warehouse-test')->allFiles() as $f) {
            $everything .= gzdecode(Storage::disk('warehouse-test')->get($f));
        }
        foreach (array_keys(WarehouseSchema::TABLES) as $t) {
            $everything .= json_encode(DB::connection('warehouse_test')->table('wh_'.$t)->get());
        }
        foreach (['Wambui', 'Confidential', 'Privileged', 'admission', 'prejudice', 'brother', 'wp.pdf'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $everything);
        }
        // only the columns of the schema exist downstream
        $this->assertSame(array_keys(WarehouseSchema::TABLES['firm_daily']), DB::connection('warehouse_test')->getSchemaBuilder()->getColumnListing('wh_firm_daily'));
    }

    public function test_the_schema_refuses_text_and_unknown_columns(): void
    {
        $row = ['date' => '2026-10-04', 'tenant_key' => 'acme', 'event' => 'login', 'count' => 3];
        $this->assertSame($row, WarehouseSchema::check('firm_events_daily', $row));

        foreach ([
            ['event' => 'Client Wambui called about the debt'],      // free text in a code column
            ['client_name' => 'Wambui'],                             // a column the schema does not have
            ['count' => 'many'],
            ['tenant_key' => 'Acme Advocates'],
        ] as $bad) {
            try {
                WarehouseSchema::check('firm_events_daily', array_merge($row, $bad));
                $this->fail('accepted: '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_re_running_a_day_replaces_it_and_a_backfill_covers_several(): void
    {
        $this->seedDay();
        Artisan::call('warehouse:export', ['--date' => '2026-10-04']);
        Artisan::call('warehouse:export', ['--date' => '2026-10-04']);
        $this->assertSame(1, DB::connection('warehouse_test')->table('wh_firm_daily')->where('date', '2026-10-04')->count());

        Artisan::call('warehouse:export', ['--date' => '2026-10-04', '--days' => 3]);
        $this->assertSame(['2026-10-02', '2026-10-03', '2026-10-04'], DB::connection('warehouse_test')->table('wh_firm_daily')->orderBy('date')->pluck('date')->all());
    }

    public function test_the_warehouse_must_be_its_own_database_and_off_means_off(): void
    {
        config(['warehouse.connection' => config('database.default')]);
        $this->expectExceptionMessage('separate warehouse connection');
        Artisan::call('warehouse:export', ['--date' => '2026-10-04']);
    }

    public function test_disabled_exports_nothing(): void
    {
        config(['warehouse.enabled' => false]);
        Artisan::call('warehouse:export');
        $this->assertSame([], Storage::disk('warehouse-test')->allFiles());
        $this->assertStringStartsWith('none: ', app(WarehousePurgeStep::class)->purge(new Tenant(['slug' => $this->firm])));
    }

    public function test_deleting_a_firm_expunges_its_warehouse_rows(): void
    {
        $this->seedDay();
        Artisan::call('warehouse:export', ['--date' => '2026-10-04']);
        Storage::disk('warehouse-test')->put('warehouse/firm_daily/dt=2026-10-04/tenant=other-firm.ndjson.gz', gzencode('{}'));

        $outcome = app(WarehousePurgeStep::class)->purge(new Tenant(['slug' => $this->firm]));
        $this->assertStringStartsWith('expunged', $outcome);
        $this->assertSame(['warehouse/firm_daily/dt=2026-10-04/tenant=other-firm.ndjson.gz'], Storage::disk('warehouse-test')->allFiles());
        foreach (array_keys(WarehouseSchema::TABLES) as $t) {
            $this->assertSame(0, DB::connection('warehouse_test')->table('wh_'.$t)->where('tenant_key', $this->firm)->count(), $t);
        }
    }
}
