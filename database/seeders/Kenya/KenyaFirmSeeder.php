<?php

namespace Database\Seeders\Kenya;

use App\Actions\Clients\RegisterClient;
use App\Actions\Hearings\ScheduleHearing;
use App\Actions\Invoices\ChangeInvoiceLines;
use App\Actions\Invoices\DraftInvoice;
use App\Actions\Invoices\SubmitInvoice;
use App\Actions\Matters\OpenMatter;
use App\Actions\Payments\RecordPayment;
use App\Actions\Time\RecordTime;
use App\Branding\Branding;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\CourtType;
use App\Models\Hearing;
use App\Models\HearingType;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\TaxRate;
use App\Models\TrustTransaction;
use App\Models\User;
use App\Tenancy\IdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Fills ONE firm (the one currently active) from a KenyaDemoData entry: its look, its people (with real sign-ins), clients,
 * matters, hearings, tasks, time and invoices. Everything goes through the same actions the web app uses, so the data is
 * exactly what the screens would have produced.
 *
 * Run it inside TenantManager::runIn(). It is safe to run again: people, courts, clients and matters already there are skipped.
 */
class KenyaFirmSeeder
{
    /** @var array<string, int> email => user id */
    private array $userIds = [];

    /** @var array<string, int> client key => client id */
    private array $clientIds = [];

    /** @var array<string, Cases> matter key => matter */
    private array $matters = [];

    private CarbonImmutable $today;

    /** @param array<string, mixed> $firm one entry of KenyaDemoData::firms() */
    public function __construct(private array $firm)
    {
        $this->today = CarbonImmutable::today();
    }

    public function run(): void
    {
        $this->courts();
        $this->branding();
        $this->people();
        $this->clients();
        $this->matters();
        $this->hearings();
        $this->tasks();

        // Billing is a bonus on top of the core data: if something about it is off, the firm still works.
        foreach (['time' => fn () => $this->time(), 'invoices' => fn () => $this->invoices(), 'trust' => fn () => $this->trust()] as $what => $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                Log::warning("Demo data for {$this->firm['slug']}: {$what} skipped: ".$e->getMessage());
            }
        }
    }

    private function courts(): void
    {
        foreach (KenyaDemoData::extraCourts() as $name => $typeKey) {
            if (Court::withTrashed()->where('name', $name)->exists()) {
                continue;
            }
            $typeId = CourtType::where('catalog_key', $typeKey)->value('id');
            if ($typeId) {
                Court::create(['name' => $name, 'type' => $typeId]);
            }
        }
    }

    private function branding(): void
    {
        Branding::update([
            'name' => $this->firm['name'],
            'color' => $this->firm['color'],
            'letterhead' => $this->firm['letterhead'],
            'invoice_footer' => $this->firm['invoice_footer'],
        ], null);
    }

    private function people(): void
    {
        $identities = app(IdentityService::class);
        foreach ($this->firm['users'] as $u) {
            $user = User::withTrashed()->where('email', $u['email'])->first();
            if (! $user) {
                $user = User::create([
                    'name' => $u['name'], 'email' => $u['email'], 'password' => Hash::make($u['password']),
                    'phone_number' => $u['phone'], 'department' => $u['title'], 'hire_date' => $u['hired'],
                ]);
            }
            if ($user->trashed()) {
                $user->restore();
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$u['role']]);
            // a sign-in in the control plane (multi-firm login goes through identities) linked to this firm's user
            $identities->enrol($user, $u['password']);
            $this->userIds[$u['email']] = (int) $user->id;
        }
    }

    private function clients(): void
    {
        $register = app(RegisterClient::class);
        foreach ($this->firm['clients'] as $key => $c) {
            $existing = Client::withoutGlobalScopes()->where('phone_number', $c['phone'])->first();
            if ($existing) {
                $this->clientIds[$key] = (int) $existing->id;

                continue;
            }
            $client = $register->handle([
                'name' => $c['name'], 'phone_number' => $c['phone'], 'extra_phone_number' => $c['extra'],
                'address' => $c['address'], 'advocate_id' => $this->userIds[$c['advocate']],
            ], null, null, false);   // no welcome text message to a demo number
            $this->clientIds[$key] = (int) $client->id;
        }
    }

    private function matters(): void
    {
        $open = app(OpenMatter::class);
        foreach ($this->firm['matters'] as $m) {
            $case = Cases::withoutGlobalScopes()->where('case_number', $m['number'])->first();
            if (! $case) {
                $case = $open->handle([
                    'case_number' => $m['number'], 'description' => $m['description'], 'client_id' => $this->clientIds[$m['client']],
                    'assigned_to' => $this->userIds[$m['lawyer']], 'start_date' => $this->day($m['start'])->toDateString(),
                    'police_station' => $m['station'], 'court_id' => $this->courtId($m['court']), 'opposing_party' => $m['opposing'],
                    'case_type' => $m['type'],
                ], null);
            }
            $this->matters[$m['key']] = $case;
        }
    }

    private function hearings(): void
    {
        $schedule = app(ScheduleHearing::class);
        foreach ($this->firm['matters'] as $m) {
            $case = $this->matters[$m['key']];
            if (Hearing::where('case_id', $case->id)->exists()) {
                continue;
            }
            foreach ($m['hearings'] as $h) {
                $typeId = HearingType::where('name', $h['type'])->value('id') ?? HearingType::where('name', 'Mention')->value('id');
                if (! $typeId) {
                    continue;
                }
                $schedule->schedule([
                    'case_id' => $case->id, 'court_id' => $case->court_id, 'hearing_date' => $this->day($h['offset'])->toDateString(),
                    'hearing_type' => $typeId, 'notes' => $h['notes'], 'outcome' => $h['outcome'] ?? null,
                    'start_time' => $h['time'], 'duration_minutes' => 60, 'courtroom' => $h['room'], 'judicial_officer' => $h['officer'],
                ], null);
            }
        }
    }

    private function tasks(): void
    {
        foreach ($this->firm['tasks'] as $t) {
            if (Task::where('title', $t['title'])->exists()) {
                continue;
            }
            Task::create([
                'title' => $t['title'], 'description' => $t['description'], 'assigned_to' => $this->userIds[$t['assignee']],
                'due_date' => $this->today->addDays($t['due'])->toDateString(), 'priority' => $t['priority'], 'task_status' => $t['status'],
            ]);
        }
    }

    private function time(): void
    {
        $record = app(RecordTime::class);
        $rates = [];
        foreach ($this->firm['users'] as $u) {
            $rates[$u['email']] = ($u['rate'] ?? 1000000) / 100;   // KES per hour (the data holds cents)
        }
        foreach ($this->firm['time'] as [$key, $email, $ago, $hours, $what]) {
            $by = User::find($this->userIds[$email]);
            $record->log([
                'case_id' => $this->matters[$key]->id, 'description' => $what, 'date' => $this->day($ago)->toDateString(),
                'hours' => $hours, 'hourly_rate' => $rates[$email], 'billable' => true,
            ], $by);
        }
    }

    private function invoices(): void
    {
        $vat = TaxRate::where('catalog_key', 'ke_vat_standard')->first();
        $draft = app(DraftInvoice::class);
        $lines = app(ChangeInvoiceLines::class);
        $submit = app(SubmitInvoice::class);
        $pay = app(RecordPayment::class);
        $accounts = $this->userIds[array_key_last($this->userIds)] ?? null;

        foreach ($this->firm['invoices'] as [$key, $ago, $terms, $items, $paid]) {
            $case = $this->matters[$key];
            if (Invoice::where('case_id', $case->id)->where('invoice_date', $this->day($ago)->toDateString())->exists()) {
                continue;
            }
            $invoice = $draft->handle([
                'case_id' => $case->id, 'client_id' => $case->client_id, 'currency' => 'KES',
                'invoice_date' => $this->day($ago)->toDateString(), 'invoice_due_date' => $this->day($ago + $terms)->toDateString(),
            ], null);
            foreach ($items as [$description, $qty, $rate]) {
                // VAT at 16% on professional fees; court fees are disbursements and carry none
                $taxable = ! str_contains(strtolower($description), 'disbursement');
                $lines->add($invoice, ['description' => $description, 'quantity' => $qty, 'rate' => $rate, 'tax_rate_id' => $taxable ? $vat?->id : null], null);
            }
            $invoice = $submit->handle($invoice->fresh(), null);
            if ($paid !== null) {
                $amount = $paid === 'full' ? (float) $invoice->fresh()->total_amount : (float) $paid;
                $pay->handle($invoice->fresh(), ['amount' => $amount, 'payment_date' => $this->day($ago + 6)->toDateString(), 'method' => 'mobile_money', 'reference_number' => 'SGH'.(7000 + abs($ago) * 13).'KE'], $accounts);
            }
        }
    }

    private function trust(): void
    {
        if (TrustTransaction::query()->exists()) {
            return;
        }
        $by = $this->userIds[array_key_first($this->userIds)] ?? null;
        if (! $by) {
            return;
        }
        $n = 0;
        foreach (array_slice($this->matters, 0, 3, true) as $case) {
            $n++;
            TrustTransaction::post($case->client_id, $case->id, 'deposit', 150000 * $n, 'Client deposit held on account', 'DEP-'.(4100 + $n), $by);
            TrustTransaction::post($case->client_id, $case->id, 'disbursement', 25000 * $n, 'Court filing fees paid from trust', 'DIS-'.(4200 + $n), $by);
        }
    }

    private function courtId(string $name): int
    {
        return (int) (Court::where('name', $name)->value('id') ?? throw new \RuntimeException("Court [{$name}] is not in this firm's courts."));
    }

    /** A court-sitting day: $offset days from today, moved to the next Monday if it falls on a weekend. */
    private function day(int $offset): CarbonImmutable
    {
        $d = $this->today->addDays($offset);
        while ($d->isWeekend()) {
            $d = $d->addDay();
        }

        return $d;
    }
}
