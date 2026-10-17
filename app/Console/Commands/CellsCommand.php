<?php

namespace App\Console\Commands;

use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Cell;
use Illuminate\Console\Command;

/**
 * TEN-4: manage the cell registry. Adding capacity is `cells add`, never a code change.
 *
 *   cells list
 *   cells add eu-1 --host=db-eu-1.internal --region=eu --capacity=400 --password-env=CELL_PASSWORD_EU1
 *   cells update eu-1 --accepting=0          (stop placing new firms; existing ones stay)
 *   cells update eu-1 --replica-host=db-eu-1-ro   (reports read from it, ENG-23)
 */
class CellsCommand extends Command
{
    protected $signature = 'cells {action : list|add|update} {name? : cell name}
        {--host=} {--port=} {--username=} {--password-env= : name of a CELL_PASSWORD_* variable holding the password}
        {--sqlite-directory=} {--region=} {--capacity=} {--accepting= : 1 or 0}
        {--files-disk= : disk (config/filesystems.php) for files of firms placed here, e.g. an in-region bucket}
        {--queue= : queue lane for background work of firms placed here}
        {--replica-host= : read replica for reports and the dashboard (ENG-23); "none" removes it} {--replica-port=}';

    protected $description = 'List, add or update database cells (TEN-4)';

    public function handle(CellRegistry $registry): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            $load = $registry->load();
            $rows = Cell::orderBy('name')->get()->map(fn (Cell $c) => [
                $c->name, $c->region ?? '-', $c->host ?? '(template)', ($load[$c->name] ?? 0).'/'.$c->capacity, $c->accepting ? 'yes' : 'no',
                $c->files_disk ?? '(default)', $c->queue ?? '(default)', $c->replica_host ? $c->replica_host.($c->replica_port ? ':'.$c->replica_port : '') : '-',
            ])->all();
            foreach (array_diff_key($load, array_flip(Cell::pluck('name')->all())) as $name => $n) {
                $rows[] = [$name, '-', '(template, no row)', (string) $n, 'n/a', '(default)', '(default)', config('database.replica.host') ?: '-'];
            }
            $this->table(['cell', 'region', 'host', 'firms/capacity', 'accepting', 'files disk', 'queue', 'replica'], $rows);

            return self::SUCCESS;
        }

        $name = (string) $this->argument('name');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $name)) {
            $this->error('A cell name is 1-32 lowercase letters, digits or dashes.');

            return self::FAILURE;
        }
        $password = $this->option('password-env');
        if ($password !== null && ! str_starts_with($password, 'CELL_PASSWORD_')) {
            $this->error('--password-env must name a CELL_PASSWORD_* variable (the password itself is never stored).');

            return self::FAILURE;
        }
        $values = array_filter([
            'host' => $this->option('host'), 'port' => $this->option('port') !== null ? (int) $this->option('port') : null,
            'username' => $this->option('username'), 'password_env' => $password, 'sqlite_directory' => $this->option('sqlite-directory'),
            'region' => $this->option('region'), 'capacity' => $this->option('capacity') !== null ? (int) $this->option('capacity') : null,
            'accepting' => $this->option('accepting') !== null ? (bool) (int) $this->option('accepting') : null,
            'files_disk' => $this->option('files-disk'), 'queue' => $this->option('queue'),
            'replica_host' => $this->option('replica-host'), 'replica_port' => $this->option('replica-port') !== null ? (int) $this->option('replica-port') : null,
        ], fn ($v) => $v !== null);
        if (($values['replica_host'] ?? null) === 'none') {
            $values['replica_host'] = $values['replica_port'] = null;   // array_filter has run: these clear the columns
        }
        if (isset($values['files_disk']) && ! is_array(config("filesystems.disks.{$values['files_disk']}"))) {
            $this->error("No disk [{$values['files_disk']}] in config/filesystems.php.");

            return self::FAILURE;
        }
        if (isset($values['queue']) && ! preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $values['queue'])) {
            $this->error('A queue name is 1-64 lowercase letters, digits, dots, dashes or underscores.');

            return self::FAILURE;
        }

        if ($action === 'add') {
            if (Cell::where('name', $name)->exists()) {
                $this->error("Cell [{$name}] already exists; use `cells update`.");

                return self::FAILURE;
            }
            Cell::create(['name' => $name] + $values);
            $this->info("Cell [{$name}] added.");
        } elseif ($action === 'update') {
            $cell = Cell::where('name', $name)->first();
            if (! $cell) {
                $this->error("No such cell: {$name}.");

                return self::FAILURE;
            }
            $cell->update($values);
            $this->info("Cell [{$name}] updated.");
        } else {
            $this->error('Action must be list, add or update.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
