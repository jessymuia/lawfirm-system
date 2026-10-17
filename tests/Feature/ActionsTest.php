<?php

namespace Tests\Feature;

use App\Actions\ActionException;
use App\Actions\Documents\StoreDocument;
use App\Actions\Invoices\ChangeInvoiceLines;
use App\Actions\Invoices\DraftInvoice;
use App\Actions\Invoices\SubmitInvoice;
use App\Actions\Matters\ChangeMatterStatus;
use App\Actions\Matters\OpenMatter;
use App\Actions\Payments\RecordPayment;
use App\Actions\Time\BillTime;
use App\Actions\Time\RecordTime;
use App\Actions\Trust\PostTrustEntry;
use App\Actions\Trust\VoidTrustEntry;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\TrustTransaction;
use App\Models\User;
use App\Support\FirmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * ENG-9: business operations are actions, callable without a request or a signed-in person (a job, the scheduler, an
 * import, a test) and enforcing the same rules as the screens.
 */
class ActionsTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $lawyer;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lawyer = $this->userWithPermissions([]);
        $this->matter = Cases::factory()->create(['assigned_to' => $this->lawyer->id]);
        $this->assertNull(Auth::user(), 'actions run here with nobody signed in');
    }

    private function issued(float $amount): Invoice
    {
        $inv = app(DraftInvoice::class)->handle(['case_id' => $this->matter->id, 'client_id' => $this->matter->client_id,
            'invoice_date' => '2026-10-01', 'invoice_due_date' => '2026-10-31'], null);
        app(ChangeInvoiceLines::class)->add($inv, ['description' => 'Advice', 'quantity' => 1, 'rate' => $amount, 'tax' => 0], null);

        return app(SubmitInvoice::class)->handle($inv->fresh(), $this->lawyer);
    }

    public function test_a_matter_opens_from_the_console_with_its_lawyers_defaults_and_the_firms_conflict_policy(): void
    {
        $data = ['case_number' => 'HCCC 1/2026', 'description' => 'Imported', 'client_id' => $this->matter->client_id, 'assigned_to' => $this->lawyer->id,
            'start_date' => '2026-10-01', 'police_station' => 'Central', 'court_id' => Court::factory()->create()->id, 'opposing_party' => 'Acme Ltd',
            'case_type' => 'civil'];
        $m = app(OpenMatter::class)->handle($data, null);
        $this->assertSame('HCCC 1/2026', $m->case_number);
        $this->assertTrue(DB::table('matter_parties')->where('case_id', $m->id)->where('name', 'Acme Ltd')->exists(), 'the opposing party joins the register');

        // a firm that requires a cleared check cannot be bypassed by calling the action without a person
        FirmSettings::set('conflicts.required', true, $this->lawyer->id);
        try {
            app(OpenMatter::class)->handle(['case_number' => 'HCCC 2/2026'] + $data, null);
            $this->fail('expected the conflict policy to refuse');
        } catch (ActionException $e) {
            $this->assertSame([422, 'conflict_check_required'], [$e->status, $e->reason]);
        }
        $this->assertFalse(Cases::where('case_number', 'HCCC 2/2026')->exists());

        $this->expectExceptionMessage("Cannot move a case from 'open' to 'nonsense'");
        app(ChangeMatterStatus::class)->handle($m->fresh(), 'nonsense', null);
    }

    public function test_time_is_billed_once_with_its_codes_and_billed_time_is_frozen(): void
    {
        $t = app(RecordTime::class)->log(['case_id' => $this->matter->id, 'description' => 'Drafting', 'date' => '2026-10-02', 'hours' => 2,
            'hourly_rate' => 5000, 'utbms_task_code' => 'L210', 'utbms_activity_code' => 'A103'], $this->lawyer);
        $inv = app(BillTime::class)->handle($this->matter->id, $this->matter->client_id, null);

        $this->assertSame('draft', $inv->workflow_status);
        $this->assertEquals(10000, $inv->fresh()->total_amount);
        $this->assertSame('L210', $inv->items()->value('utbms_task_code'));
        $this->assertTrue((bool) $t->fresh()->billed);

        try {
            app(RecordTime::class)->correct($t->fresh(), ['hours' => 3], null);
            $this->fail('billed time must not change');
        } catch (ActionException $e) {
            $this->assertStringContainsString('already been billed', $e->getMessage());
        }
        $this->expectExceptionMessage('No unbilled billable time entries');
        app(BillTime::class)->handle($this->matter->id, $this->matter->client_id, null);
    }

    public function test_lines_are_priced_by_the_action_and_freeze_once_issued(): void
    {
        $inv = $this->issued(1000);
        $this->assertEquals(1000, $inv->fresh()->total_amount);

        $this->expectExceptionMessage('Items of a submitted invoice cannot be changed.');
        app(ChangeInvoiceLines::class)->add($inv, ['description' => 'More', 'quantity' => 1, 'rate' => 1, 'tax' => 0], null);
    }

    public function test_payments_never_take_an_invoice_below_zero_and_online_money_is_capped_not_refused(): void
    {
        $inv = $this->issued(1000);
        $pay = fn (float $amount, bool $online = false) => app(RecordPayment::class)->handle($inv, ['amount' => $amount, 'payment_date' => '2026-10-03',
            'method' => 'cash'], $this->lawyer->id, $online);

        $pay(600);
        try {
            $pay(500);
            $this->fail('over-payment at the counter is refused');
        } catch (ActionException $e) {
            $this->assertStringContainsString('exceeds the outstanding balance of 400', $e->getMessage());
        }
        $this->assertEquals(400, $pay(500, true)->amount, 'money already taken online is recorded up to what is owed');
        $this->assertNull($pay(10, true), 'nothing is owed any more');
        $this->assertEquals(1000, $inv->fresh()->amount_paid);
    }

    public function test_a_deposit_cannot_be_voided_once_its_money_is_spent(): void
    {
        $client = Client::factory()->create();
        $post = fn (string $type, float $amount) => app(PostTrustEntry::class)->handle(['client_id' => $client->id, 'type' => $type, 'amount' => $amount,
            'description' => $type], $this->lawyer->id);
        $deposit = $post('deposit', 1000);
        $post('disbursement', 700);

        try {
            app(VoidTrustEntry::class)->handle($deposit, null);
            $this->fail('voiding the deposit would overdraw the client');
        } catch (ActionException $e) {
            $this->assertStringContainsString('already been disbursed', $e->getMessage());
        }
        $this->expectExceptionMessage('would overdraw');
        $post('disbursement', 400);
    }

    public function test_a_voided_entry_leaves_the_balance_and_cannot_be_voided_twice(): void
    {
        $client = Client::factory()->create();
        $d = app(PostTrustEntry::class)->handle(['client_id' => $client->id, 'type' => 'deposit', 'amount' => 250, 'description' => 'x'], $this->lawyer->id);
        $this->assertEquals(0, app(VoidTrustEntry::class)->handle($d, $this->lawyer));
        $this->assertSame($this->lawyer->id, (int) TrustTransaction::find($d->id)->voided_by);

        $this->expectExceptionMessage('already voided');
        app(VoidTrustEntry::class)->handle($d, null);
    }

    public function test_documents_from_any_source_land_on_the_firm_disk_and_version_together(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::fake('local');
        $store = app(StoreDocument::class);
        $first = $store->handle($this->matter->id, 'first draft', ['title' => 'Plaint', 'filename' => 'plaint.txt'], null);
        $second = $store->handle($this->matter->id, 'second draft', ['title' => 'Plaint', 'filename' => 'plaint.txt', 'document_group_id' => $first->document_group_id], $this->lawyer);

        $this->assertSame($first->id, (int) $first->document_group_id, 'a new document is its own group');
        $this->assertSame([2, $first->id, true], [(int) $second->version, (int) $second->document_group_id, (bool) $second->is_current]);
        $this->assertFalse((bool) $first->fresh()->is_current);
        $this->assertSame(hash('sha256', 'second draft'), $second->checksum);
        $this->assertSame(['text/plain', 'txt'], [$second->mimetype, $second->extension]);
        $this->assertStringContainsString('cases/docs/', $second->full_path);
        $this->assertSame('second draft', Storage::disk($second->disk)->get($second->full_path));
        $this->assertSame(1, Document::where('document_group_id', $first->id)->where('is_current', true)->count());
    }

    public function test_an_action_refusal_renders_as_the_apis_error_shape(): void
    {
        $res = (new ActionException('Nope', 409, 'why'))->render();
        $this->assertSame(409, $res->getStatusCode());
        $this->assertSame(['success' => false, 'message' => 'Nope', 'data' => null, 'code' => 'why'], $res->getData(true));
    }
}
