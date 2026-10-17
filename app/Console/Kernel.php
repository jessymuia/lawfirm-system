<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // FUN-3: hearing-date reminders. Runs once daily; reminds attorneys
        // and clients 1 day ahead of a hearing. `reminder_sent_at` on the
        // hearings table guarantees this never double-sends even if the
        // scheduler fires more than once for the same day.
        $schedule->command('hearings:send-reminders --days=1')
            ->dailyAt('08:00')
            ->withoutOverlapping();

        // FUN-16: connected calendars, behind the syncs each hearing/task change triggers.
        $schedule->command('calendar:sync')->everyFifteenMinutes()->withoutOverlapping();

        // PLAT-11: webhook deliveries and retries.
        // PLAT-12: the heartbeat of the status page and the SLA record; if this stops, the page says so.
        $schedule->command('health:probe')->everyMinute()->withoutOverlapping();

        $schedule->command('webhooks:deliver')->everyMinute()->withoutOverlapping();

        // PLAT-8: remove expired platform-support sessions and their ephemeral users.
        $schedule->command('support:expire')->everyMinute()->withoutOverlapping();

        // SEC-13: retention disposal. Only firms that set a period and switched auto-purge on are touched.
        $schedule->command('retention:run --execute')->dailyAt('03:00')->withoutOverlapping();

        // PLAT-4: invoice due subscription periods and run dunning. After tenants:usage so overage sees yesterday's gauges.
        $schedule->command('billing:run')->dailyAt('02:30')->withoutOverlapping();

        // ENG-24: per-tenant usage gauges (users, matters, storage).
        $schedule->command('tenants:usage')->dailyAt('01:30')->withoutOverlapping();
        // TEN-8: catch hand edits and half-applied migrations before the next migration trips over them.
        $schedule->command('schema:drift')->dailyAt('04:30')->withoutOverlapping();
        // ENG-21: expired firm-wide export files
        $schedule->command('exports:prune')->hourly()->withoutOverlapping();
        $schedule->command('documents:prune-uploads')->hourly()->withoutOverlapping();   // ENG-22
        $schedule->command('warehouse:export')->dailyAt('02:20')->withoutOverlapping()->when(fn () => (bool) config('warehouse.enabled'));   // ENG-26
        // DATA-11: move audit months older than each firm's plan window to archive files; prune archives past retention
        $schedule->command('audits:archive')->monthlyOn(1, '03:30')->withoutOverlapping();
        // TEN-9: offboarded firms past their retention window (never under legal hold, never without a final export)
        $schedule->command('tenants:purge-due')->dailyAt('05:00')->withoutOverlapping();

        // TEN-14: every firm snapshotted nightly (database + files as a pair), and one firm restore-drilled each week;
        // both do nothing on a self-hosted (single-firm) install, which has backup:run below
        $schedule->command('tenants:snapshot --all')->dailyAt('01:45')->withoutOverlapping()
            ->onFailure(fn () => logger()->critical('The nightly firm snapshots (tenants:snapshot --all) failed for at least one firm.'));
        $schedule->command('tenants:restore-drill')->weeklyOn(0, '04:00')->withoutOverlapping()
            ->onFailure(fn () => logger()->critical('The weekly restore drill (tenants:restore-drill) failed: see tenant_restores.'));

        // ENG-6: nightly database + document backup (see
        // docs/ops/BACKUP_STRATEGY.md and app/Console/Commands/RunBackup.php).
        // Runs at 02:00 — after normal business hours, before the 08:00
        // reminder job above — so a slow dump never contends with either.
        $schedule->command('backup:run')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onFailure(function () {
                logger()->critical('Nightly backup (backup:run) failed — see scheduler log for the underlying error.');
            });
        // CARRY-7: once a month the newest backup is restored beside the install, checked and timed (docs/ops/BACKUP_STRATEGY.md)
        $schedule->command('backup:drill')->monthlyOn(1, '05:00')->withoutOverlapping()
            ->onFailure(fn () => logger()->critical('The monthly restore drill (backup:drill) failed: see the report under drills/ on the backup disk.'));
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
