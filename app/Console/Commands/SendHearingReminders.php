<?php

namespace App\Console\Commands;

use App\Flags\Flags;
use App\Jobs\SendSmsJob;
use App\Matters\MatterAccess;
use App\Matters\WallAttempts;
use App\Models\Hearing;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

class SendHearingReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --days lets this be run ad-hoc for a different window than the
     * scheduled default (see App\Console\Kernel).
     *
     * @var string
     */
    protected $signature = 'hearings:send-reminders {--days=1 : How many days before the hearing to remind}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send SMS reminders for hearings coming up in N days that have not already been reminded';

    public function handle(): int
    {
        // PLAT-3: ops kill switch, per firm or everywhere.
        if (! app(Flags::class)->enabled('hearing_sms_reminders')) {
            $this->warn('Flag hearing_sms_reminders is off: no reminders sent.');

            return CommandAlias::SUCCESS;
        }

        $daysBefore = max(0, (int) $this->option('days'));
        $targetDate = Carbon::today()->addDays($daysBefore)->toDateString();

        $hearings = Hearing::with(['case.attorney', 'case.client', 'court'])
            ->whereDate('hearing_date', $targetDate)
            ->whereNull('reminder_sent_at')
            ->get();

        if ($hearings->isEmpty()) {
            $this->info("No hearings on {$targetDate} needing a reminder.");

            return CommandAlias::SUCCESS;
        }

        $sent = 0;

        foreach ($hearings as $hearing) {
            $case = $hearing->case;

            if (! $case) {
                Log::warning('Hearing reminder skipped: case no longer exists', ['hearing_id' => $hearing->id]);

                continue;
            }

            $courtName = $hearing->court?->displayName() ?? 'court';
            $when = Carbon::parse($hearing->hearing_date)->format('d/m/Y');

            // SEC-11: never notify a screened user about a matter they are walled from (logged as an attempt).
            $attorney = $case->attorney;
            if ($attorney && MatterAccess::isWalled($attorney->id, $case->id)) {
                WallAttempts::record($attorney->id, 'notification', $case->id, null, ['method' => 'hearings:send-reminders']);
                $attorney = null;
            }
            if ($attorney && $attorney->phone_number) {
                SendSmsJob::dispatch(
                    $attorney->phone_number,
                    "Reminder: hearing for case {$case->case_number} at {$courtName} on {$when}."
                );
                $sent++;
            }

            $client = $case->client;
            if ($client && $client->phone_number) {
                SendSmsJob::dispatch(
                    $client->phone_number,
                    "Reminder: your case {$case->case_number} has a hearing at {$courtName} on {$when}."
                );
                $sent++;
            }

            $hearing->update(['reminder_sent_at' => now()]);
        }

        $this->info("Queued {$sent} reminder SMS across {$hearings->count()} hearing(s) for {$targetDate}.");

        return CommandAlias::SUCCESS;
    }
}
