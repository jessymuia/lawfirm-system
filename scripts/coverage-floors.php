<?php

/*
 * ENG-17 / CARRY-1: coverage gate. Reads a Clover report and fails when overall line coverage, or coverage of a
 * money/permission path, falls below its floor. A blanket target was rejected in the original plan; these floors
 * are where a defect is unrecoverable (billing, trust accounting, authorization).
 *
 *   php scripts/coverage-floors.php build/clover.xml
 *
 * Floors are the measured value rounded down. Raise them when coverage improves; never lower one without a reason
 * in the pull request.
 */

$floors = [
    'overall' => 79,
    // money
    'app/Models/Invoice*.php' => 93,
    'app/Models/Payment.php' => 100,
    'app/Models/TrustTransaction.php' => 97,
    'app/Http/Controllers/InvoiceController.php' => 93,
    'app/Http/Controllers/InvoiceItemController.php' => 90,
    'app/Http/Controllers/PaymentController.php' => 93,
    'app/Http/Controllers/TrustTransactionController.php' => 95,
    'app/Services/SequenceService.php' => 100,
    // ENG-9: the money rules themselves (invoice lines and totals, payments against the balance, trust posting and voids)
    'app/Actions/Invoices/*.php' => 100,
    'app/Actions/Payments/*.php' => 100,
    'app/Actions/Trust/*.php' => 100,
    'app/Billing/*.php' => 97,
    // permissions and access
    'app/Http/Middleware/AuthorizeRoute.php' => 100,
    'app/Http/Middleware/RequireAuthzDeclaration.php' => 100,
    'app/Matters/*.php' => 93,
    'app/Http/Controllers/RolesManagementController.php' => 70,
    // 91, not the 95 measured before TEN-8: SchemaDrift's MySQL branches (scratch reference database, information_schema
    // triggers) only run in the backend-mysql job, and coverage is measured on SQLite. Tested there, not counted here.
    'app/Tenancy/*.php' => 91,
];

$file = $argv[1] ?? 'build/clover.xml';
if (! is_file($file)) {
    fwrite(STDERR, "No coverage report at {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
$root = realpath(__DIR__.'/..').'/';
$files = [];
$missed = [];
foreach ($xml->xpath('//file') as $f) {
    $path = str_replace($root, '', (string) $f['name']);
    $m = $f->metrics;
    $files[$path] = [(int) $m['statements'], (int) $m['coveredstatements']];
    $missed[$path] = array_map(fn ($l) => (int) $l['num'], $f->xpath("line[@type='stmt'][@count='0']"));
}

$pct = fn (array $pairs) => ($t = array_sum(array_column($pairs, 0))) ? 100 * array_sum(array_column($pairs, 1)) / $t : null;

$failed = 0;
foreach ($floors as $pattern => $floor) {
    $match = $pattern === 'overall'
        ? array_filter($files, fn ($k) => str_starts_with($k, 'app/'), ARRAY_FILTER_USE_KEY)
        : array_filter($files, fn ($k) => fnmatch($pattern, $k), ARRAY_FILTER_USE_KEY);
    $p = $pct(array_values($match));
    if ($p === null) {
        printf("%-55s  no files matched (floor %d%%)\n", $pattern, $floor);
        $failed++;

        continue;
    }
    $ok = $p + 1e-9 >= $floor;
    $ok || $failed++;
    printf("%-55s %6.1f%%  floor %3d%%  %s\n", $pattern, $p, $floor, $ok ? 'ok' : 'BELOW FLOOR');
    if (! $ok && $pattern !== 'overall') {
        // say where: the lines no test runs, per file
        foreach (array_keys($match) as $path) {
            $missed[$path] && printf("    %s: lines %s\n", $path, implode(', ', $missed[$path]));
        }
    }
}

exit($failed ? 1 : 0);
