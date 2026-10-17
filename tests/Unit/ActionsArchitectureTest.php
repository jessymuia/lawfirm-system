<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ENG-9: the core domain's controllers authorize, translate the request and answer; what changes the books (matters,
 * clients, invoices, payments, trust, time, hearings, documents) happens in an action under app/Actions, so the same
 * rules hold for a job, the console or an import.
 */
class ActionsArchitectureTest extends TestCase
{
    private const CONTROLLERS = ['Cases', 'Client', 'Document', 'Hearing', 'Invoice', 'InvoiceItem', 'Payment', 'TimeEntry', 'Today', 'TrustTransaction'];

    /** Writes that are not business operations: the document access log. */
    private const ALLOWED = ['/\bDocumentAccess::create\(/'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_domain_controllers_leave_writes_to_actions(): void
    {
        $offenders = [];
        foreach (self::CONTROLLERS as $name) {
            foreach (file($this->root()."/app/Http/Controllers/{$name}Controller.php") as $n => $line) {
                $line = (string) preg_replace('/(app\(\w+::class\)|\$this->\w+)->\w+\(/', '', $line);   // calling an action is the point
                $write = preg_match('/\b[A-Z]\w*::create\(|->(update|save|delete|forceDelete|restore)\(|DB::transaction\(|->insert(GetId)?\(/', $line);
                $allowed = array_filter(self::ALLOWED, fn ($p) => preg_match($p, $line));
                if ($write && ! $allowed) {
                    $offenders[] = "{$name}Controller.php:".($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'Move this write into an action under app/Actions (see docs/ACTIONS.md).');
    }

    public function test_actions_know_nothing_of_http_or_the_signed_in_person(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/app/Actions')) as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'ActionException.php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            foreach (['Illuminate\Http\Request', 'request(', 'Auth::', 'auth()', 'abort('] as $banned) {
                $this->assertStringNotContainsString($banned, $src, $file->getFilename().': actions take the acting person and the data as arguments');
            }
        }
    }

    public function test_every_document_is_filed_through_one_action(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/app')) as $file) {
            if ($file->getExtension() === 'php' && ! str_ends_with($file->getPathname(), 'Actions/Documents/StoreDocument.php')
                && preg_match('/\bDocument::create\(|new Document\b/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = substr($file->getPathname(), strlen($this->root()) + 1);
            }
        }

        $this->assertSame([], $offenders, 'File documents with App\Actions\Documents\StoreDocument (disk, prefix, checksum, versions).');
    }
}
