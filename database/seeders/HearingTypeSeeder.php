<?php

namespace Database\Seeders;

use App\Catalog\SystemCatalog;
use Illuminate\Database\Seeder;

/**
 * TEN-11: courts, court types and hearing types are platform-owned system rows
 * defined in App\Catalog\SystemCatalog. Kept as a seeder so existing seeding
 * entry points still work; it only runs the (idempotent) catalog sync.
 */
class HearingTypeSeeder extends Seeder
{
    public function run(): void
    {
        SystemCatalog::sync();
    }
}
