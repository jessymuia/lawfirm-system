<?php

namespace App\Console\Commands;

use App\Api\TypeScriptTypes;
use Illuminate\Console\Command;

/** ENG-15: (re)generate the web app's API types from the Resources' shapes. */
class ApiTypes extends Command
{
    protected $signature = 'api:types {--check : fail if the committed file is out of date instead of writing it}';

    protected $description = 'Generate TypeScript types for the API from the v1 Resources';

    public function handle(): int
    {
        $path = base_path(TypeScriptTypes::FILE);
        $want = TypeScriptTypes::render();
        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $want) {
                $this->error(TypeScriptTypes::FILE.' is out of date: run php artisan api:types');

                return self::FAILURE;
            }
            $this->info('API types are up to date.');

            return self::SUCCESS;
        }
        file_put_contents($path, $want);
        $this->info('Wrote '.TypeScriptTypes::FILE);

        return self::SUCCESS;
    }
}
