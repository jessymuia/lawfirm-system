<?php

namespace App\Tenancy;

use App\Tenancy\Models\Tenant;

/**
 * TEN-9: an extra step of permanent deletion, listed in config tenancy.purge_steps. It runs after the firm's database
 * and files are gone. Steps named `encryption_key` (TEN-13: destroy the firm's data key, which crypto-shreds any copy
 * left in backups) and `warehouse` (ENG-26: expunge the firm's rows) replace the "none" lines in the purge report.
 * Throw to stop the purge; return a short outcome for the report (no firm data).
 */
interface PurgeStep
{
    public function name(): string;

    public function purge(Tenant $tenant): string;
}
