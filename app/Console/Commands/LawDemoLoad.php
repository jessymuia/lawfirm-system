<?php

namespace App\Console\Commands;

use App\Demo\DemoLoader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Load more demo data. Each sample firm is a complete, separate firm with its own database and sign-ins.
 *
 *   php artisan law:demo-load --list              what exists and what is loaded
 *   php artisan law:demo-load coast-legal         one sample firm
 *   php artisan law:demo-load --all               every sample firm
 */
class LawDemoLoad extends Command
{
    protected $signature = 'law:demo-load {packs?* : slug(s) of the sample firms to load (default: the default sample firm)}
        {--all : load every sample firm}
        {--list : only list the sample firms and whether each is loaded}';

    protected $description = 'Load more sample (demo) firms with complete data and sign-ins; use --list to see them';

    public function handle(DemoLoader $demo): int
    {
        $demo->reporting(fn (string $level, string $m) => $this->{$level}($m));
        try {
            $demo->guard();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $rows = [];
            foreach ($demo->packs() as $slug => $f) {
                $rows[] = [$slug, $f['name'], count($f['users']), count($f['clients']), count($f['matters']), $f['default'] ? 'default (loaded on first run)' : 'on demand', $demo->status($slug)];
            }
            $this->table(['Pack (slug)', 'Firm', 'People', 'Clients', 'Matters', 'Kind', 'Status'], $rows);
            $this->line('Load:   php artisan law:demo-load <slug> [<slug> …]   or   --all');
            $this->line('Remove: php artisan law:demo-remove <slug> …          or   --all');

            return self::SUCCESS;
        }

        try {
            $slugs = $demo->resolve((array) $this->argument('packs'), (bool) $this->option('all'));
            $demo->prepareControlPlane();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $admin = $demo->superAdmin();
        $failed = 0;
        foreach ($slugs as $slug) {
            try {
                $demo->load($slug);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$slug}: ".$e->getMessage());
            }
        }
        self::summary($this, $demo, $slugs, $admin);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $slugs  @param array<string, string> $admin */
    public static function summary(Command $c, DemoLoader $demo, array $slugs, array $admin): void
    {
        $c->newLine();
        $c->info('Law Management System: sign-in details (demo data)');
        $c->line('Operator (super admin):  '.$admin['email'].'  /  '.$admin['password']);
        $rows = [];
        foreach ($slugs as $slug) {
            $firm = $demo->packs()[$slug];
            foreach ($firm['users'] as $u) {
                $rows[] = [$firm['name'], $u['name'], $u['role'], $u['email'], $u['password']];
            }
        }
        $c->table(['Firm', 'Person', 'Role', 'Email', 'Password'], $rows);
        $c->line('Choose "Firm" on the sign-in page. The default firm answers on plain http://localhost; the others on <slug>.localhost.');
    }
}
