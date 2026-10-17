<?php

namespace App\Tenancy;

use App\Tenancy\Models\Cell;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TEN-4: where each firm's database lives, and where a new firm goes.
 *
 * The capacity limit of database-per-tenant is tables per server (~40 tables x firms), not connections, so capacity is
 * added by adding cells. A cell row overrides host/port/username/password of the tenant connection template; the
 * password is never stored, only the name of the environment variable (or *_FILE secret) that holds it.
 * Region (TEN-15): a firm with a region is only ever placed in a cell of that region.
 */
class CellRegistry
{
    /** Full connection config for a firm's database on its cell. */
    public function connectionConfig(Tenant $tenant): array
    {
        return $this->serverConfig($tenant->cell ?: 'default') + ['database' => $tenant->database];
    }

    /** Connection config for a cell's server, without a database (for CREATE/DROP DATABASE and scratch databases). */
    public function serverConfig(string $cellName): array
    {
        $config = (array) config('database.connections.'.config('tenancy.tenant_connection_template'));
        $cell = $this->find($cellName);
        if ($cell) {
            foreach (['host', 'port', 'username'] as $k) {
                if ($cell->{$k} !== null && $cell->{$k} !== '') {
                    $config[$k] = $cell->{$k};
                }
            }
            if ($cell->password_env) {
                $password = config('tenancy.cell_passwords')[$cell->password_env] ?? null;
                if ($password === null) {
                    throw new RuntimeException("Cell [{$cell->name}]: {$cell->password_env} is not set (it must be a CELL_PASSWORD_* variable).");
                }
                $config['password'] = $password;
            }
        }
        unset($config['database'], $config['url']);

        return $config;
    }

    /** Where SQLite firm files of this cell live (SQLite deployments and tests only). */
    public function sqliteDirectory(string $cellName): string
    {
        return rtrim($this->find($cellName)?->sqlite_directory ?: database_path('tenants'), '/');
    }

    /**
     * Pick the cell for a new firm: accepting, below capacity, in the firm's region if it has one; least loaded first.
     * With no cell rows at all the answer is the template-only 'default' cell (the single-cell setup).
     */
    public function place(?string $region = null): string
    {
        if (! $this->installed() || Cell::count() === 0) {
            if ($region !== null) {
                throw new RuntimeException("No cell serves region [{$region}]. Add one with cells add <name> --region={$region}.");
            }

            return 'default';
        }

        $load = $this->load();
        $candidates = Cell::where('accepting', true)
            ->when($region !== null, fn ($q) => $q->where('region', $region))
            ->get()
            ->filter(fn (Cell $c) => ($load[$c->name] ?? 0) < $c->capacity)
            ->sortBy(fn (Cell $c) => [($load[$c->name] ?? 0) / max(1, $c->capacity), $c->name]);

        $cell = $candidates->first();
        if (! $cell) {
            throw new RuntimeException($region !== null
                ? "No cell in region [{$region}] is accepting firms or has room. Add one (cells add) or raise a capacity."
                : 'No cell is accepting firms or has room. Add one (cells add) or raise a capacity.');
        }

        return $cell->name;
    }

    /** @return array<string,int> cell name => firms placed there (purged firms do not count) */
    public function load(): array
    {
        return Tenant::where('status', '!=', 'purged')->selectRaw('cell, count(*) as n')->groupBy('cell')->pluck('n', 'cell')
            ->map(fn ($n) => (int) $n)->all();
    }

    public function find(string $name): ?Cell
    {
        return $this->installed() ? Cell::where('name', $name)->first() : null;
    }

    private ?bool $installed = null;

    private function installed(): bool
    {
        // Only a positive answer is remembered: the control plane may be migrated after this object was created.
        if ($this->installed) {
            return true;
        }

        return $this->installed = Schema::connection('control')->hasTable('cells');
    }
}
