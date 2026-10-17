<?php

namespace App\Console\Commands;

use App\Api\OpenApiSpec;
use App\Api\TypeScriptClient;
use Illuminate\Console\Command;

/** ENG-12: (re)generate the staff API's OpenAPI document and the typed client generated from it. */
class ApiOpenApi extends Command
{
    protected $signature = 'api:openapi {--check : fail if the committed files are out of date instead of writing them}';

    protected $description = 'Generate docs/openapi.json and the typed TypeScript client from the routes, Form Requests and Resources';

    public function handle(): int
    {
        $spec = OpenApiSpec::build();
        $files = [OpenApiSpec::FILE => OpenApiSpec::render(), TypeScriptClient::FILE => TypeScriptClient::render($spec)];
        // Line endings are not content: a Windows checkout (CRLF) must not fail the check, only a real difference may.
        $norm = fn (string $text) => rtrim(str_replace("\r\n", "\n", $text))."\n";
        $stale = array_keys(array_filter($files, fn ($want, $file) => ! is_file(base_path($file)) || $norm((string) file_get_contents(base_path($file))) !== $norm($want), ARRAY_FILTER_USE_BOTH));
        if ($this->option('check')) {
            foreach ($stale as $file) {
                $this->error("{$file} is out of date: run php artisan api:openapi");
            }
            $stale || $this->info('The OpenAPI document and the client are up to date ('.count($spec['paths']).' paths).');

            return $stale ? self::FAILURE : self::SUCCESS;
        }
        foreach ($files as $file => $content) {
            file_put_contents(base_path($file), $content);
            $this->info("Wrote {$file}");
        }

        return self::SUCCESS;
    }
}
