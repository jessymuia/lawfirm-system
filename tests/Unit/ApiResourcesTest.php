<?php

namespace Tests\Unit;

use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\TestCase;

/** ENG-10: entity response shapes are API Resources (one per entity, versioned under Resources\V1), never per-controller formatters. */
class ApiResourcesTest extends TestCase
{
    public function test_no_controller_formats_rows_itself(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2).'/app/Http/Controllers/*.php') as $file) {
            if (preg_match('/function formatRow\(/', (string) file_get_contents($file))) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'Put the response shape in app/Http/Resources/V1/<Entity>Resource.php.');
    }

    public function test_every_resource_is_versioned_and_a_json_resource(): void
    {
        $files = glob(dirname(__DIR__, 2).'/app/Http/Resources/V1/*Resource.php');
        $this->assertGreaterThanOrEqual(14, count($files));
        foreach ($files as $file) {
            $class = 'App\\Http\\Resources\\V1\\'.basename($file, '.php');
            $this->assertTrue(is_subclass_of($class, JsonResource::class), $class);
        }
    }
}
