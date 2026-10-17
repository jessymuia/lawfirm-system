<?php

namespace App\Console\Commands;

use App\Demo\DemoLoader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Remove demo data. Only the sample firms are ever touched (never a real firm): each one's database, files, sign-ins and
 * billing records are deleted, and it can be loaded again fresh afterwards.
 *
 *   php artisan law:demo-remove coast-legal       one sample firm
 *   php artisan law:demo-remove --all             every sample firm
 *   php artisan law:demo-remove --all --with-admin   ...and the demo super admin
 */
class LawDemoRemove extends Command
{
    protected $signature = 'law:demo-remove {packs?* : slug(s) of the sample firms to remove}
        {--all : remove every sample firm}
        {--with-admin : also remove the demo super admin account}
        {--force : do not ask for confirmation}';

    protected $description = 'Remove sample (demo) firms and everything in them; real firms are never touched';

    public function handle(DemoLoader $demo): int
    {
        $demo->reporting(fn (string $level, string $m) => $this->{$level}($m));
        try {
            $demo->guard();
            if (! $this->option('all') && ! $this->argument('packs')) {
                $this->error('Say which sample firm(s) to remove, or use --all. See them with: php artisan law:demo-load --list');

                return self::FAILURE;
            }
            $slugs = $demo->resolve((array) $this->argument('packs'), (bool) $this->option('all'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $loaded = array_values(array_filter($slugs, fn ($s) => $demo->status($s) === 'loaded'));
        if ($loaded === [] && ! $this->option('with-admin')) {
            $this->info('Nothing to remove: none of those sample firms is loaded.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('This permanently deletes: '.implode(', ', $loaded).($this->option('with-admin') ? ' and the demo super admin' : '').'. Continue?')) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($loaded as $slug) {
            try {
                $demo->remove($slug);
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$slug}: ".$e->getMessage());
            }
        }
        if ($this->option('with-admin') && $demo->removeSuperAdmin()) {
            $this->info('Demo super admin removed.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
