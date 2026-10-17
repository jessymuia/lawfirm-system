<?php

namespace App\Jobs;

use App\Money\Money;
use App\Queue\BulkWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-14: invoices issued before exchange rates were fixed get theirs: rate 1 for those in the firm's base currency
 * (their totals are already in it). Invoices in another currency are left without a rate, since no rate was recorded
 * when they were issued and guessing one would rewrite history; reports list them as unconverted. Queued and chunked
 * (DATA-13), idempotent, writes no audit rows. Runs in the firm it was dispatched from (TEN-3).
 */
class BackfillInvoiceFx implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 3;   // ENG-20

    public function __construct(public int $chunk = 500)
    {
        $this->onBulkLane();   // ENG-20
    }

    public function handle(Money $money): int
    {
        if (! Schema::hasColumn('invoices', 'fx_rate')) {
            return 0;
        }
        $base = $money->baseCurrency();
        $filled = 0;
        DB::table('invoices')->whereNull('fx_rate')->where('workflow_status', '!=', 'draft')
            ->where(fn ($q) => $q->where('currency', $base)->orWhereNull('currency'))->orderBy('id')
            ->chunkById($this->chunk, function ($rows) use ($base, &$filled) {
                foreach ($rows as $row) {
                    $filled += DB::table('invoices')->where('id', $row->id)->whereNull('fx_rate')->update([
                        'base_currency' => $base, 'fx_rate' => 1, 'fx_rate_on' => $row->invoice_date,
                        'base_subtotal' => $row->subtotal, 'base_total_amount' => $row->total_amount,
                    ]);
                }
            });

        return $filled;
    }
}
