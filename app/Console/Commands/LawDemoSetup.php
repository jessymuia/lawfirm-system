<?php

namespace App\Console\Commands;

use App\Demo\DemoLoader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Law Management System: makes a fresh install usable for a first look. It sets up the control plane, the platform super
 * admin and ONE complete sample firm (people with real sign-ins, clients, matters, hearings, tasks, time, invoices and a
 * subscription). More demo firms are loaded on demand with `law:demo-load`, and taken away with `law:demo-remove`.
 *
 * Safe to run again: whatever already exists is left alone.
 */
class LawDemoSetup extends Command
{
    protected $signature = 'law:demo-setup {--reset-admin : put the super admin back to the demo password}';

    protected $description = 'Set up the control plane, the platform super admin and the one default sample firm';

    public function handle(DemoLoader $demo): int
    {
        $demo->reporting(fn (string $level, string $m) => $this->{$level}($m));
        try {
            $demo->guard();
            $demo->prepareControlPlane();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $admin = $demo->superAdmin((bool) $this->option('reset-admin'));
        $slug = $demo->defaultSlug();
        try {
            $demo->load($slug);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        LawDemoLoad::summary($this, $demo, [$slug], $admin);
        $this->newLine();
        $this->line('Want more sample data?   php artisan law:demo-load --list      (see the other sample firms)');
        $this->line('                         php artisan law:demo-load --all       (load them all)');
        $this->line('Start from a clean slate: php artisan law:demo-remove --all');

        return self::SUCCESS;
    }
}
