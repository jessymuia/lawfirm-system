<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-13: a retention period per record class: filed emails, portal messages and former clients' data, not only documents. */
class RetentionClassesTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        Storage::fake('local');
        $this->officer = $this->userWithPermissions(['manage-compliance']);
        $this->travelTo('2026-10-01 09:00:00');
    }

    private function matter(Client $c, string $status = 'closed', ?string $end = '2015-06-30'): Cases
    {
        return Cases::factory()->create(['client_id' => $c->id, 'lifecycle_status' => $status, 'end_date' => $end]);
    }

    private function policy(string $class, int $years = 7, bool $auto = true): void
    {
        $this->actingAs($this->officer)->putJson("/api/v1/compliance/retention/{$class}", ['retain_years' => $years, 'auto_purge' => $auto])->assertOk();
    }

    private function email(Cases $m): int
    {
        Storage::disk('local')->put("tenants/default/email/{$m->id}.eml", 'From: wanjiru@example.com');

        return DB::table('matter_emails')->insertGetId(['case_id' => $m->id, 'thread_key' => 't'.$m->id, 'from_email' => 'wanjiru@example.com', 'from_name' => 'Wanjiru',
            'to_list' => '[]', 'subject' => 'Settlement terms', 'sent_at' => '2015-05-01', 'body_text' => 'Our offer is...', 'raw_disk' => 'local',
            'raw_path' => "tenants/default/email/{$m->id}.eml", 'raw_sha256' => str_repeat('a', 64), 'raw_size' => 25, 'source' => 'upload', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_every_class_is_listed_with_how_it_is_disposed_of(): void
    {
        $classes = collect($this->actingAs($this->officer)->getJson('/api/v1/compliance/retention')->assertOk()->json('data.classes'))->keyBy('record_class');
        $this->assertSame(['case_documents', 'matter_emails', 'portal_messages', 'inactive_clients', 'financial_records', 'audit_log'], $classes->keys()->all());
        $this->assertTrue($classes['inactive_clients']['purgeable']);
        $this->assertFalse($classes['financial_records']['purgeable']);
        $this->assertArrayNotHasKey('handler', $classes['matter_emails'], 'internal wiring is not exposed');
        $this->assertStringContainsString('anonymised', $classes['inactive_clients']['disposal']);
    }

    public function test_filed_emails_and_portal_messages_of_old_matters_are_disposed_of_but_not_newer_ones(): void
    {
        $old = $this->matter(Client::factory()->create());
        $recent = $this->matter(Client::factory()->create(), 'closed', '2024-01-01');
        $oldEmail = $this->email($old);
        $recentEmail = $this->email($recent);
        DB::table('portal_messages')->insert([['case_id' => $old->id, 'direction' => 'from_client', 'body' => 'When is the hearing?', 'created_at' => now(), 'updated_at' => now()],
            ['case_id' => $recent->id, 'direction' => 'from_client', 'body' => 'Thanks', 'created_at' => now(), 'updated_at' => now()]]);
        $this->policy('matter_emails');
        $this->policy('portal_messages');

        $due = $this->actingAs($this->officer)->getJson('/api/v1/compliance/retention/due?class=matter_emails')->assertOk()->json('data');
        $this->assertSame(['matter_emails', '2019-10-01', 1, [$oldEmail]], [$due['record_class'], $due['cutoff'], $due['due_count'], $due['ids']]);
        $this->getJson('/api/v1/compliance/retention/due?class=audit_log')->assertUnprocessable();

        $this->artisan('retention:run --execute')->assertSuccessful()->expectsOutputToContain('[matter_emails]: disposed 1 record(s)');

        $stub = DB::table('matter_emails')->find($oldEmail);
        $this->assertSame(['[disposed under the retention schedule]', null, null], [$stub->subject, $stub->body_text, $stub->from_email]);
        Storage::disk('local')->assertMissing("tenants/default/email/{$old->id}.eml");
        $this->assertSame('Settlement terms', DB::table('matter_emails')->where('id', $recentEmail)->value('subject'));
        $this->assertSame(['Thanks'], DB::table('portal_messages')->pluck('body')->all());
        $this->assertSame(2, DB::table('retention_disposals')->count());
        $this->assertSame(2, DB::table('audits')->where('event', 'retention_disposed')->count());

        $this->artisan('retention:run --execute')->assertSuccessful();   // idempotent
        $this->assertSame(2, DB::table('retention_disposals')->count());
    }

    public function test_former_clients_are_anonymised_and_current_or_held_ones_are_not(): void
    {
        $former = Client::factory()->create(['name' => 'Kamau Holdings', 'phone_number' => '0711000001', 'created_at' => '2012-01-01']);
        $this->matter($former);
        $active = Client::factory()->create(['name' => 'Active Ltd', 'phone_number' => '0711000002', 'created_at' => '2012-01-01']);
        $this->matter($active);
        $this->matter($active, 'open', null);   // still has an open matter
        $held = Client::factory()->create(['name' => 'Held Ltd', 'phone_number' => '0711000003', 'created_at' => '2012-01-01']);
        $heldMatter = $this->matter($held);
        $this->actingAs($this->officer)->postJson('/api/v1/compliance/holds', ['scope_type' => 'case', 'scope_id' => $heldMatter->id, 'reason' => 'appeal pending'])->assertCreated();
        $this->policy('inactive_clients', 7, false);

        $due = $this->getJson('/api/v1/compliance/retention/due?class=inactive_clients')->json('data');
        $this->assertSame([[$former->id], 1], [$due['ids'], $due['held_count']]);
        $this->artisan('retention:run --execute')->assertSuccessful()->expectsOutputToContain('auto-purge is off');
        $this->assertSame('Kamau Holdings', $former->fresh()->name);

        $this->policy('inactive_clients');
        $this->artisan('retention:run --execute')->assertSuccessful();
        $this->assertSame(["Erased client #{$former->id}", '[erased]'], [$former->fresh()->name, $former->fresh()->phone_number]);
        $this->assertSame(['Active Ltd', 'Held Ltd'], [$active->fresh()->name, $held->fresh()->name]);
        $this->assertSame(0, DB::table('audits')->where('auditable_type', Client::class)->where('auditable_id', $former->id)
            ->where('new_values', 'like', '%Kamau%')->count(), 'the audit trail no longer holds the name');
    }

    public function test_a_disposed_documents_searchable_text_goes_with_it(): void
    {
        $m = $this->matter(Client::factory()->create());
        Storage::disk('local')->put('tenants/default/cases/docs/plaint.pdf', 'x');
        $doc = Document::create(['case_id' => $m->id, 'title' => 'Plaint', 'filename' => 'plaint.pdf', 'filepath' => 'tenants/default/cases/docs/plaint.pdf',
            'full_path' => 'tenants/default/cases/docs/plaint.pdf', 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 1, 'extension' => 'pdf', 'version' => 1, 'is_current' => true]);
        DB::table('document_texts')->updateOrInsert(['document_id' => $doc->id], ['state' => 'done', 'content' => 'the plaintiff Wanjiru claims', 'created_at' => now(), 'updated_at' => now()]);
        $this->policy('case_documents');

        $this->artisan('retention:run --execute')->assertSuccessful();
        $this->assertSame(0, DB::table('document_texts')->where('document_id', $doc->id)->count());
        $this->assertSame('document', DB::table('retention_disposals')->value('record_type'));
    }
}
