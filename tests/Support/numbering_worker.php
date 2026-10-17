<?php

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;

/**
 * Worker for NumberingConcurrencyTest: boots the app in its own process and
 * creates records as fast as it can, so several copies genuinely race.
 * Usage: php numbering_worker.php <invoice|payment> <count> <caseId> <clientId> [invoiceId]
 * Prints one line per success ("OK <number>") and "ERR <message>" per failure.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $kind, $count, $caseId, $clientId] = $argv;
$invoiceId = $argv[5] ?? null;

for ($i = 0; $i < (int) $count; $i++) {
    try {
        if ($kind === 'invoice') {
            $row = Invoice::create([
                'case_id' => $caseId, 'client_id' => $clientId,
                'invoice_date' => date('Y-m-d'), 'invoice_due_date' => date('Y-m-d'),
                'workflow_status' => 'draft',
            ]);
            echo 'OK '.$row->invoice_number."\n";
        } else {
            $row = Payment::create([
                'invoice_id' => $invoiceId, 'amount' => 1, 'payment_date' => date('Y-m-d'),
                'method' => 'cash', 'received_by' => 1,
            ]);
            echo 'OK '.$row->receipt_number."\n";
        }
    } catch (Throwable $e) {
        echo 'ERR '.substr(str_replace("\n", ' ', $e->getMessage()), 0, 200)."\n";
    }
}
