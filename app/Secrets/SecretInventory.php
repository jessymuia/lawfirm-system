<?php

namespace App\Secrets;

use App\Tenancy\Keys\FirmCrypt;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-15: walk every registered encrypted column and (a) report which key can read each value, (b) re-encrypt under the
 * current key. This is the APP_KEY rotation procedure: put the new key in APP_KEY, the old one(s) in APP_PREVIOUS_KEYS,
 * run `secrets:rotate --execute`, confirm with `secrets:check`, then remove the old keys. TEN-13: a firm with its own data
 * key counts as current only under that key, so the same run moves its values onto it.
 */
class SecretInventory
{
    /** @return list<array{scope:string,table:string,column:string,key:string}> */
    public function targets(string $scope): array
    {
        return array_values(array_filter(config('secrets.encrypted'), fn ($t) => $t['scope'] === $scope));
    }

    private function conn(string $scope)
    {
        return $scope === 'control' ? DB::connection('control') : DB::connection();
    }

    private function exists(string $scope, string $table): bool
    {
        try {
            return Schema::connection($scope === 'control' ? 'control' : null)->hasTable($table);
        } catch (\Throwable) {
            return false;   // no control plane on this install
        }
    }

    /** The key values in this scope should be under now: the active firm's data key when it has one (TEN-13), else the app key. */
    private function currentOnly(string $scope): Encrypter
    {
        $key = $scope === 'tenant' ? FirmCrypt::encrypter()->getKey() : app('encrypter')->getKey();

        return new Encrypter($key, config('app.cipher'));
    }

    /** Everything that may still read this scope's values: for a firm, its key then the app keys. */
    private function reader(string $scope): StringEncrypter
    {
        return $scope === 'tenant' ? FirmCrypt::encrypter() : Crypt::getFacadeRoot();
    }

    /** @return array{current:int,previous:int,broken:int,tables:array} counts across one scope */
    public function check(string $scope): array
    {
        $sum = ['current' => 0, 'previous' => 0, 'broken' => 0, 'tables' => []];
        $cur = $this->currentOnly($scope);
        foreach ($this->targets($scope) as $t) {
            if (! $this->exists($scope, $t['table'])) {
                continue;
            }
            $n = ['current' => 0, 'previous' => 0, 'broken' => 0];
            foreach ($this->conn($scope)->table($t['table'])->whereNotNull($t['column'])->get([$t['key'], $t['column']]) as $row) {
                $n[$this->state($cur, $this->reader($scope), (string) $row->{$t['column']})]++;
            }
            foreach ($n as $k => $v) {
                $sum[$k] += $v;
            }
            $sum['tables']["{$t['table']}.{$t['column']}"] = $n;
        }

        return $sum;
    }

    private function state(Encrypter $current, StringEncrypter $reader, string $value): string
    {
        try {
            $current->decryptString($value);

            return 'current';
        } catch (\Throwable) {
        }
        try {
            $reader->decryptString($value);   // also tries APP_PREVIOUS_KEYS (and, for a firm, the app key)

            return 'previous';
        } catch (\Throwable) {
            return 'broken';
        }
    }

    /** @return array{rotated:int,already_current:int,broken:int,dry_run:bool} */
    public function rotate(string $scope, bool $execute): array
    {
        $r = ['rotated' => 0, 'already_current' => 0, 'broken' => 0, 'dry_run' => ! $execute];
        $cur = $this->currentOnly($scope);
        $reader = $this->reader($scope);
        foreach ($this->targets($scope) as $t) {
            if (! $this->exists($scope, $t['table'])) {
                continue;
            }
            foreach ($this->conn($scope)->table($t['table'])->whereNotNull($t['column'])->get([$t['key'], $t['column']]) as $row) {
                $old = (string) $row->{$t['column']};
                $state = $this->state($cur, $reader, $old);
                if ($state === 'current') {
                    $r['already_current']++;
                } elseif ($state === 'broken') {
                    $r['broken']++;
                } else {
                    if ($execute) {
                        // compare-and-set: a value changed meanwhile is left alone and picked up by the next run
                        $this->conn($scope)->table($t['table'])->where($t['key'], $row->{$t['key']})->where($t['column'], $old)
                            ->update([$t['column'] => $scope === 'tenant' ? FirmCrypt::encryptString($reader->decryptString($old)) : Crypt::encryptString(Crypt::decryptString($old))]);
                    }
                    $r['rotated']++;
                }
            }
        }

        return $r;
    }
}
