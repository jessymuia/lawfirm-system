<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** ENG-8: controllers never validate inline; every rule set lives in a Form Request under app/Http/Requests. */
class FormRequestsTest extends TestCase
{
    public function test_no_controller_validates_inline(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2).'/app/Http/Controllers/*.php') as $file) {
            foreach (file($file) as $n => $line) {
                if (preg_match('/->validate\(|Validator::make\(|validator\(/', $line)) {
                    $offenders[] = basename($file).':'.($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'Move these rules into a Form Request (app/Http/Requests/<Controller>/<Action>Request.php).');
    }

    public function test_form_requests_leave_authorization_to_the_route(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Http/Requests')) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Concerns/')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            $this->assertStringContainsString('extends FormRequest', $src, $file->getFilename());
            // authorization is declared once, on the route (authz:*, ENG-11); a request deciding it too would hide that
            $this->assertMatchesRegularExpression('/function authorize\(\): bool\s*\{\s*return true;/', $src, $file->getPathname());
        }
    }
}
