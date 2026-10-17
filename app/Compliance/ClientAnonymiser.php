<?php

namespace App\Compliance;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * SEC-13: replaces a client's personal fields (config compliance.client_pii) and redacts them inside the audit trail.
 * Used by DSAR erasure and by retention of former clients' data. Rows are never deleted: matters, invoices, payments and
 * trust entries still point at the (now anonymous) client.
 */
class ClientAnonymiser
{
    /** @return int audit rows redacted */
    public function anonymise(Client $client): int
    {
        $pii = (array) config('compliance.client_pii');
        $redacted = 0;
        DB::transaction(function () use ($client, $pii, &$redacted) {
            $mask = ['name' => 'Erased client #'.$client->id, 'phone_number' => '[erased]', 'extra_phone_number' => null, 'address' => '[erased]'];
            DB::table('clients')->where('id', $client->id)->update(array_intersect_key($mask, array_flip($pii)) + ['updated_at' => now()]);

            // the audit trail must not keep the data that was just erased
            foreach (DB::table('audits')->where('auditable_type', Client::class)->where('auditable_id', $client->id)->get(['id', 'old_values', 'new_values']) as $a) {
                $upd = [];
                foreach (['old_values', 'new_values'] as $col) {
                    $v = json_decode((string) $a->{$col}, true);
                    if (is_array($v)) {
                        foreach ($pii as $k) {
                            if (array_key_exists($k, $v)) {
                                $v[$k] = '[erased]';
                            }
                        }
                        $upd[$col] = json_encode($v);
                    }
                }
                if ($upd) {
                    DB::table('audits')->where('id', $a->id)->update($upd);
                    $redacted++;
                }
            }
        });

        return $redacted;
    }
}
