<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Email\MimeMessage;
use App\Models\Cases;
use App\Models\User;
use App\Support\FirmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-15: email filed to matters by their address or by uploading an .eml. */
class EmailFilingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private const SECRET = 'inbound-secret-0123456789abcdefghijklmnop';

    private User $u;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['email_ingest.domain' => 'in.firm.test', 'email_ingest.webhook_secret' => self::SECRET, 'documents.search.engine' => 'scan']);
        $this->u = $this->userWithPermissions([P::LIST_DOCUMENTS, P::CREATE_DOCUMENTS, P::VIEW_DOCUMENTS, P::DELETE_DOCUMENTS, P::LIST_CASES, P::ACCESS_ALL_MATTERS, P::MANAGE_ORGANISATION]);
        $this->u->forceFill(['email' => 'wanjiru@firm.test'])->save();
        $this->matter = Cases::factory()->create(['case_number' => 'ELRC 9/2026']);
    }

    private function address(?Cases $m = null): string
    {
        return $this->actingAs($this->u)->getJson('/api/v1/cases/'.($m ?? $this->matter)->id.'/emails')->assertOk()->json('data.address');
    }

    /** A real-shaped message: multipart/mixed with an alternative text/html body and a PDF attachment. */
    private function eml(string $to, string $from = 'Wanjiru <wanjiru@firm.test>', string $id = 'abc123@mail.firm.test', string $extra = ''): string
    {
        $pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        return "Return-Path: <wanjiru@firm.test>\r\nFrom: {$from}\r\nTo: Opposing Counsel <counsel@other.test>\r\nCc: {$to}\r\n"
            .'Subject: =?UTF-8?B?'.base64_encode('Re: Settlement – without prejudice')."?=\r\nDate: Mon, 05 Oct 2026 09:15:00 +0300\r\n"
            ."Message-ID: <{$id}>\r\nIn-Reply-To: <first@mail.other.test>\r\nReferences: <first@mail.other.test>\r\n{$extra}MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\nThis is a multi-part message.\r\n--outer\r\n"
            ."Content-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n--inner\r\nContent-Type: text/plain; charset=\"utf-8\"\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n\r\nOur client accepts KES 2,500,000 in full and final settlem=\r\nent.\r\n\r\nRegards=2C Wanjiru\r\n"
            ."--inner\r\nContent-Type: text/html; charset=\"utf-8\"\r\n\r\n<p>Our client accepts <b>KES 2,500,000</b></p>\r\n--inner--\r\n"
            ."--outer\r\nContent-Type: application/octet-stream; name=\"Consent order.pdf\"\r\nContent-Disposition: attachment; filename=\"Consent order.pdf\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n--outer\r\nContent-Type: application/x-msdownload; name=\"setup.exe\"\r\n"
            ."Content-Disposition: attachment; filename=\"setup.exe\"\r\nContent-Transfer-Encoding: base64\r\n\r\nTVqQAAMAAAAEAAAA\r\n--outer--\r\n";
    }

    private function inbound(string $raw, array $form = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/v1/inbound/email/'.self::SECRET, ['body-mime' => $raw] + $form, ['Accept' => 'application/json']);
    }

    public function test_the_parser_reads_the_parts_people_and_threads(): void
    {
        $m = MimeMessage::parse($this->eml('x@in.firm.test'));
        $this->assertSame('Re: Settlement – without prejudice', $m->subject());
        $this->assertSame("Our client accepts KES 2,500,000 in full and final settlement.\n\nRegards, Wanjiru", $m->text, 'quoted-printable, soft breaks, text part preferred');
        $this->assertTrue($m->hadHtml);
        $this->assertSame([['email' => 'wanjiru@firm.test', 'name' => 'Wanjiru']], $m->addresses('from'));
        $this->assertSame(['Consent order.pdf', 'setup.exe'], array_column($m->attachments, 'filename'));
        $this->assertStringStartsWith('%PDF-1.4', $m->attachments[0]['content']);
        $this->assertSame(MimeMessage::parse("Message-ID: <first@mail.other.test>\n\nhi")->threadKey(), $m->threadKey(), 'a reply joins its conversation');
        $this->assertSame('2026-10-05T06:15:00+00:00', $m->date()->utc()->toIso8601String());

        $html = MimeMessage::parse("Content-Type: text/html; charset=windows-1252\n\n<p>Caf\xe9 &amp; co</p><script>alert(1)</script>");
        $this->assertSame('Café & co', $html->text, 'HTML only ever becomes text');
    }

    public function test_mail_sent_to_the_matter_address_is_filed_with_its_attachments(): void
    {
        $to = $this->address();
        $this->assertMatchesRegularExpression('/^matter-[a-z0-9]{24}@in\.firm\.test$/', $to);
        $this->assertSame($to, $this->address(), 'the address is stable');

        $this->inbound($this->eml($to))->assertOk()->assertJsonPath('filed', 1);
        $list = $this->actingAs($this->u)->getJson("/api/v1/cases/{$this->matter->id}/emails")->assertOk()->json('data.emails');
        $this->assertSame(1, $list['total']);
        $this->assertSame(['Re: Settlement – without prejudice', 'wanjiru@firm.test', 1], [$list['data'][0]['subject'], $list['data'][0]['from_email'], (int) $list['data'][0]['attachments']]);

        $one = $this->getJson("/api/v1/cases/{$this->matter->id}/emails/{$list['data'][0]['id']}")->assertOk()->json('data');
        $this->assertSame('Consent order.pdf', $one['attachments'][0]['filename']);
        $this->assertSame([['filename' => 'setup.exe', 'reason' => 'file type not accepted']], $one['skipped_attachments']);
        $this->assertSame('counsel@other.test', $one['to'][0]['email']);
        $this->assertSame(1, DB::table('documents')->where('case_id', $this->matter->id)->where('filename', 'Consent order.pdf')->count(), 'the attachment is a matter document');
        $this->assertStringContainsString('Message-ID: <abc123@mail.firm.test>', $this->get("/api/v1/cases/{$this->matter->id}/emails/{$one['id']}/raw")->assertOk()->streamedContent());

        // filed twice (forwarded again, or uploaded): kept once
        $this->inbound($this->eml($to))->assertOk()->assertJsonPath('filed', 0);
        $this->assertSame(1, DB::table('matter_emails')->count());
        $this->actingAs($this->u)->getJson("/api/v1/cases/{$this->matter->id}/emails?q=settlement")->assertJsonPath('data.emails.total', 1);
        $this->getJson("/api/v1/cases/{$this->matter->id}/emails?q=nothing-like-it")->assertJsonPath('data.emails.total', 0);
    }

    public function test_one_message_to_two_matters_is_filed_to_both_and_unknown_addresses_to_none(): void
    {
        $other = Cases::factory()->create();
        $a = $this->address();
        $b = $this->address($other);
        $this->inbound($this->eml($a.', '.$b))->assertJsonPath('filed', 2);
        $this->inbound($this->eml('matter-aaaaaaaaaaaaaaaaaaaaaaaa@in.firm.test', id: 'x@y'))->assertJsonPath('filed', 0);
        $this->inbound($this->eml(str_replace('in.firm.test', 'elsewhere.test', $a), id: 'z@y'))->assertJsonPath('filed', 0);   // right token, wrong domain
    }

    public function test_only_the_firms_people_may_file_by_mail_unless_the_firm_says_otherwise(): void
    {
        $to = $this->address();
        $this->inbound($this->eml($to, 'Stranger <spam@bad.test>', 's1@bad'))->assertOk()->assertJsonPath('reason', 'sender not allowed');
        $this->inbound($this->eml($to, 'Colleague <kamau@firm.test>', 's2@ok'))->assertJsonPath('filed', 0);   // same domain, not a user

        $this->actingAs($this->u)->putJson('/api/v1/email-filing/settings', ['senders' => 'firm_domains'])->assertOk();
        $this->inbound($this->eml($to, 'Colleague <kamau@firm.test>', 's3@ok'))->assertJsonPath('filed', 1);
        FirmSettings::set('email_ingest_senders', 'anyone');
        $this->inbound($this->eml($to, 'Client <client@gmail.test>', 's4@ok'))->assertJsonPath('filed', 1);
        $this->actingAs($this->userWithPermissions([P::LIST_DOCUMENTS]))->putJson('/api/v1/email-filing/settings', ['senders' => 'anyone'])->assertForbidden();
    }

    public function test_the_webhook_needs_its_secret_and_mailguns_signature_when_set(): void
    {
        $to = $this->address();
        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/inbound/email/wrong-secret-0123456789abcdefghijklmnop', ['body-mime' => $this->eml($to)])->assertNotFound();

        config(['email_ingest.mailgun_signing_key' => 'mg-key']);
        $this->inbound($this->eml($to), ['timestamp' => (string) time(), 'token' => 't', 'signature' => 'forged'])->assertNotFound();
        $ts = (string) time();
        $this->inbound($this->eml($to), ['timestamp' => $ts, 'token' => 't', 'signature' => hash_hmac('sha256', $ts.'t', 'mg-key')])->assertJsonPath('filed', 1);

        // a raw message/rfc822 body (an MTA piping to curl) works too
        config(['email_ingest.mailgun_signing_key' => null]);
        $this->call('POST', '/api/v1/inbound/email/'.self::SECRET, [], [], [], ['CONTENT_TYPE' => 'message/rfc822', 'HTTP_ACCEPT' => 'application/json'], $this->eml($to, id: 'raw@pipe'))
            ->assertOk()->assertJsonPath('filed', 1);
    }

    public function test_staff_file_saved_emails_and_matter_access_applies(): void
    {
        $file = UploadedFile::fake()->createWithContent('message.eml', $this->eml('nobody@else.test'));
        $this->actingAs($this->u)->post("/api/v1/cases/{$this->matter->id}/emails", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(['upload', $this->u->id], [DB::table('matter_emails')->value('source'), (int) DB::table('matter_emails')->value('filed_by')]);
        $this->post("/api/v1/cases/{$this->matter->id}/emails", ['file' => UploadedFile::fake()->createWithContent('notes.eml', "just some text\nno headers")], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $id = DB::table('matter_emails')->value('id');
        $this->deleteJson("/api/v1/cases/{$this->matter->id}/emails/{$id}")->assertOk();
        $this->assertSame(1, DB::table('documents')->where('filename', 'Consent order.pdf')->count(), 'attachments stay in the matter');

        config(['matters.access' => 'restricted']);
        $outsider = $this->userWithPermissions([P::LIST_DOCUMENTS, P::CREATE_DOCUMENTS]);
        $this->actingAs($outsider)->getJson("/api/v1/cases/{$this->matter->id}/emails")->assertNotFound();
    }
}
