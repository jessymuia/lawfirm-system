<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\SendSmsJob;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\HearingType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-10: the client portal (sign-in by texted code, own matters, shared documents both ways, messages, invoices). */
class ClientPortalTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $staff;

    private Client $client;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendSmsJob::class]);
        Storage::fake('local');
        $this->staff = $this->userWithPermissions([P::MANAGE_CLIENT_PORTAL, P::LIST_CASES, P::LIST_CLIENTS, P::LIST_DOCUMENTS]);
        $this->client = Client::factory()->create(['name' => 'Wanjiku Mwangi', 'phone_number' => '+254 712 345 678']);
        $this->matter = Cases::factory()->create(['client_id' => $this->client->id, 'case_number' => 'HCC-1', 'assigned_to' => $this->staff->id]);
    }

    private function enable(?Client $c = null): void
    {
        $this->actingAs($this->staff)->putJson('/api/v1/clients/'.($c ?? $this->client)->id.'/portal', ['enabled' => true])->assertOk()
            ->assertJsonPath('data.portal_enabled', true);
    }

    /** Sign in as the client the way the portal does: ask for a code, then use it (the texted code is replaced by a known one). */
    private function signIn(string $phone = '0712345678'): string
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/portal/login', ['phone' => $phone])->assertOk();
        DB::table('portal_login_codes')->whereNull('used_at')->update(['code_hash' => hash_hmac('sha256', '424242', (string) config('app.key'))]);

        return $this->postJson('/api/v1/portal/verify', ['phone' => $phone, 'code' => '424242'])->assertOk()->json('data.token');
    }

    private function portal(string $method, string $url, string $token, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/v1/portal'.$url, $data, ['Authorization' => 'Bearer '.$token]);
    }

    private function document(Cases $m, string $title): Document
    {
        Storage::disk('local')->put("docs/{$title}.pdf", "%PDF {$title}");

        return Document::create(['title' => $title, 'case_id' => $m->id, 'version' => 1, 'is_current' => true, 'filename' => "{$title}.pdf",
            'filepath' => "docs/{$title}.pdf", 'full_path' => "docs/{$title}.pdf", 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 9, 'extension' => 'pdf']);
    }

    public function test_a_client_signs_in_with_a_code_texted_to_their_phone(): void
    {
        $this->enable();
        Bus::assertDispatched(SendSmsJob::class, 1);   // the invitation

        $token = $this->signIn('0712 345 678');        // written differently from the record
        Bus::assertDispatched(SendSmsJob::class, 2);   // the code
        $this->portal('GET', '/me', $token)->assertOk()->assertJsonPath('data.name', 'Wanjiku Mwangi')->assertJsonPath('data.phone', '•••••••••678');
        $this->assertNotNull(DB::table('portal_accounts')->value('last_login_at'));

        // the code is single use
        $this->postJson('/api/v1/portal/verify', ['phone' => '0712345678', 'code' => '424242'])->assertUnprocessable();

        $this->portal('POST', '/logout', $token)->assertOk();
        $this->portal('GET', '/me', $token)->assertUnauthorized()->assertJsonPath('code', 'portal_signed_out');
    }

    public function test_sign_in_says_nothing_about_who_is_a_client_and_codes_wear_out(): void
    {
        $answer = $this->postJson('/api/v1/portal/login', ['phone' => '0712345678'])->assertOk()->json('message');   // not enabled yet
        $this->assertSame($answer, $this->postJson('/api/v1/portal/login', ['phone' => '0799000000'])->assertOk()->json('message'));
        Bus::assertNotDispatched(SendSmsJob::class);

        $this->enable();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/portal/login', ['phone' => '0712345678'])->assertOk();
        DB::table('portal_login_codes')->update(['code_hash' => hash_hmac('sha256', '424242', (string) config('app.key'))]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/portal/verify', ['phone' => '0712345678', 'code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/portal/verify', ['phone' => '0712345678', 'code' => '424242'])->assertUnprocessable();   // five wrong tries end it

        $this->postJson('/api/v1/portal/login', ['phone' => '0712345678'])->assertOk();
        DB::table('portal_login_codes')->whereNull('used_at')->update(['code_hash' => hash_hmac('sha256', '424242', (string) config('app.key')), 'expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/portal/verify', ['phone' => '0712345678', 'code' => '424242'])->assertUnprocessable();   // expired
    }

    public function test_a_client_sees_only_their_own_matters_and_what_was_shared_with_them(): void
    {
        $other = Cases::factory()->create(['case_number' => 'OTHER-1', 'client_id' => Client::factory()->create()->id]);
        $shared = $this->document($this->matter, 'Plaint');
        $internal = $this->document($this->matter, 'Internal memo');
        $foreign = $this->document($other, 'Foreign');
        DB::table('hearings')->insert(['case_id' => $this->matter->id, 'court_id' => $this->matter->court_id, 'hearing_date' => now()->addWeek()->toDateString(),
            'hearing_type' => HearingType::factory()->create()->id, 'notes' => 'Partner thinks the judge dislikes us', 'outcome' => '', 'created_at' => now(), 'updated_at' => now()]);
        $this->enable();
        $share = $this->actingAs($this->staff)->postJson("/api/v1/cases/{$this->matter->id}/portal/documents", ['document_id' => $shared->id, 'notify' => true])
            ->assertCreated()->json('data.0.id');
        $this->postJson("/api/v1/cases/{$this->matter->id}/portal/documents", ['document_id' => $foreign->id])->assertNotFound();

        $t = $this->signIn();
        $this->portal('GET', '/matters', $t)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.case_number', 'HCC-1')
            ->assertJsonPath('data.0.next_hearing', now()->addWeek()->toDateString());
        $detail = $this->portal('GET', "/matters/{$this->matter->id}", $t)->assertOk()->assertJsonCount(1, 'data.documents')->assertJsonPath('data.documents.0.title', 'Plaint');
        $this->assertStringNotContainsString('judge dislikes', $detail->getContent(), 'internal hearing notes never reach the portal');
        $this->assertStringNotContainsString('Internal memo', $detail->getContent());
        $this->portal('GET', "/matters/{$other->id}", $t)->assertNotFound();

        $this->assertSame('%PDF Plaint', $this->portal('GET', "/documents/{$share}", $t)->assertOk()->streamedContent());
        $this->assertNotNull(DB::table('portal_documents')->where('id', $share)->value('first_viewed_at'));
        $this->assertSame(1, DB::table('audits')->where('event', 'portal_document_downloaded')->count());

        // withdrawn: gone from the portal
        $this->actingAs($this->staff)->deleteJson("/api/v1/cases/{$this->matter->id}/portal/documents/{$share}")->assertOk();
        $this->portal('GET', "/documents/{$share}", $t)->assertNotFound();
        $this->assertSame(0, DB::table('portal_documents')->where('document_id', $internal->id)->count());
    }

    public function test_documents_and_messages_go_both_ways(): void
    {
        $this->enable();
        $t = $this->signIn();
        $this->portal('POST', "/matters/{$this->matter->id}/documents", $t, ['title' => 'ID copy', 'file' => UploadedFile::fake()->create('id.pdf', 20, 'application/pdf')])
            ->assertCreated()->assertJsonPath('data.direction', 'from_client');
        $this->portal('POST', "/matters/{$this->matter->id}/documents", $t, ['title' => 'Script', 'file' => UploadedFile::fake()->create('x.exe', 1, 'application/x-msdownload')])
            ->assertUnprocessable();
        $this->assertSame(1, Document::withoutGlobalScopes()->where('case_id', $this->matter->id)->where('title', 'ID copy')->count(), 'stored as a matter document');
        $this->portal('POST', "/matters/{$this->matter->id}/messages", $t, ['body' => 'When is the next hearing?'])->assertCreated();

        // the firm sees it as unread, opens the tab, answers
        $this->actingAs($this->staff)->getJson('/api/v1/client-portal/unread')->assertOk()->assertJsonPath('data.0.case_number', 'HCC-1')->assertJsonPath('data.0.unread', 1);
        $this->getJson("/api/v1/cases/{$this->matter->id}/portal")->assertOk()->assertJsonPath('data.client.portal_enabled', true)
            ->assertJsonPath('data.documents.0.direction', 'from_client')->assertJsonPath('data.messages.0.body', 'When is the next hearing?');
        $this->getJson('/api/v1/client-portal/unread')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/cases/{$this->matter->id}/portal/messages", ['body' => 'On the 14th.'])->assertCreated();
        Bus::assertDispatched(SendSmsJob::class, fn ($job) => true);

        $this->portal('GET', '/matters', $t)->assertJsonPath('data.0.unread_messages', 1);
        $this->portal('GET', "/matters/{$this->matter->id}", $t)->assertJsonPath('data.messages.1.body', 'On the 14th.')
            ->assertJsonPath('data.messages.1.sender', $this->staff->name)->assertJsonPath('data.messages.0.sender', null);
        $this->portal('GET', '/matters', $t)->assertJsonPath('data.0.unread_messages', 0);

        // a matter that is not theirs
        $other = Cases::factory()->create(['client_id' => Client::factory()->create()->id]);
        $this->portal('POST', "/matters/{$other->id}/messages", $t, ['body' => 'hi'])->assertNotFound();
    }

    public function test_a_client_sees_issued_invoices_and_pays_one_online(): void
    {
        $mk = function (string $status, float $total) {
            $inv = Invoice::create(['case_id' => $this->matter->id, 'client_id' => $this->client->id, 'invoice_date' => now()->toDateString(),
                'invoice_due_date' => now()->addDays(7)->toDateString(), 'workflow_status' => 'draft']);
            DB::table('invoices')->where('id', $inv->id)->update(['currency' => 'KES', 'subtotal' => $total, 'total_amount' => $total]);
            $status === 'submitted' && DB::table('invoices')->where('id', $inv->id)->update(['workflow_status' => 'submitted']);

            return $inv;
        };
        $issued = $mk('submitted', 2500);
        $mk('draft', 999);
        $this->enable();
        $t = $this->signIn();

        $this->portal('GET', '/invoices', $t)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.outstanding', 2500);
        $url = $this->portal('POST', "/invoices/{$issued->id}/pay", $t)->assertOk()->json('data.url');
        $this->assertMatchesRegularExpression('#^/pay/[A-Za-z0-9]{40}$#', $url);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1'.$url)->assertOk()->assertJsonPath('data.outstanding', 2500);

        $stranger = Client::factory()->create();
        $someoneElses = Invoice::create(['case_id' => Cases::factory()->create(['client_id' => $stranger->id])->id, 'client_id' => $stranger->id, 'invoice_date' => now()->toDateString(),
            'invoice_due_date' => now()->toDateString(), 'workflow_status' => 'draft']);
        $this->portal('POST', "/invoices/{$someoneElses->id}/pay", $t)->assertNotFound();
    }

    public function test_withdrawing_access_ends_open_sessions(): void
    {
        $this->enable();
        $t = $this->signIn();
        $this->actingAs($this->staff)->putJson("/api/v1/clients/{$this->client->id}/portal", ['enabled' => false])->assertOk()->assertJsonPath('data.portal_enabled', false);
        $this->portal('GET', '/me', $t)->assertUnauthorized();
        $this->assertSame(1, DB::table('audits')->where('event', 'portal_access_withdrawn')->count());
    }

    public function test_permission_and_plan(): void
    {
        $this->actingAs($this->userWithPermissions([P::LIST_CLIENTS, P::LIST_CASES]))->putJson("/api/v1/clients/{$this->client->id}/portal", ['enabled' => true])->assertForbidden();
        $this->getJson("/api/v1/cases/{$this->matter->id}/portal")->assertForbidden();

        $this->usePlan('starter');
        $this->actingAs($this->staff)->putJson("/api/v1/clients/{$this->client->id}/portal", ['enabled' => true])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/portal/login', ['phone' => '0712345678'])->assertForbidden();
    }
}
