<?php

namespace App\Compliance;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Security\SecurityPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SEC-13: legal holds. A hold on a client covers all of that client's matters and their documents; a hold on a matter
 * covers its documents. A hold blocks deletion (soft or hard), retention disposal and erasure. Placing and releasing are
 * audited, releasing needs a reason, and nothing is ever deleted from the hold table itself.
 *
 * Lookups use the query builder, not the models, so ethical-wall and matter scopes can never hide a hold from the check.
 */
class LegalHolds
{
    public const SCOPES = ['client', 'case'];

    public function place(string $scopeType, int $scopeId, string $reason, ?int $actor): object
    {
        if (! in_array($scopeType, self::SCOPES, true)) {
            throw new ComplianceException('Scope must be "client" or "case".');
        }
        if (trim($reason) === '') {
            throw new ComplianceException('A reason is required to place a legal hold.');
        }
        // the model lookups are scoped on purpose: a person walled off from a matter cannot place a hold on it either
        $exists = $scopeType === 'client' ? Client::whereKey($scopeId)->exists() : Cases::whereKey($scopeId)->exists();
        if (! $exists) {
            throw new ComplianceException('No such '.$scopeType.'.', 404);
        }
        if ($this->activeFor($scopeType, $scopeId)->exists()) {
            throw new ComplianceException('That '.$scopeType.' is already under a legal hold.', 409);
        }
        $id = DB::table('legal_holds')->insertGetId([
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'reason' => $reason, 'placed_by' => $actor,
            'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('legal_hold_placed', 'legal_hold', $id, [], ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'reason' => $reason]);

        return DB::table('legal_holds')->where('id', $id)->first();
    }

    public function release(int $id, string $reason, ?int $actor): object
    {
        $hold = DB::table('legal_holds')->where('id', $id)->first() ?? throw new ComplianceException('No such hold.', 404);
        if ($hold->released_at) {
            throw new ComplianceException('That hold was already released.', 409);
        }
        if (trim($reason) === '') {
            throw new ComplianceException('A reason is required to release a legal hold.');
        }
        DB::table('legal_holds')->where('id', $id)->whereNull('released_at')->update([
            'released_by' => $actor, 'released_at' => now(), 'release_reason' => $reason, 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('legal_hold_released', 'legal_hold', $id, ['scope_type' => $hold->scope_type, 'scope_id' => $hold->scope_id], ['reason' => $reason]);

        return DB::table('legal_holds')->where('id', $id)->first();
    }

    public function activeFor(string $scopeType, int $scopeId)
    {
        return DB::table('legal_holds')->where('scope_type', $scopeType)->where('scope_id', $scopeId)->whereNull('released_at');
    }

    public function clientHeld(int $clientId): bool
    {
        return $this->activeFor('client', $clientId)->exists();
    }

    public function caseHeld(int $caseId): bool
    {
        if ($this->activeFor('case', $caseId)->exists()) {
            return true;
        }
        $client = DB::table('cases')->where('id', $caseId)->value('client_id');

        return $client && $this->clientHeld((int) $client);
    }

    /** Is this client held, directly or through any of its matters? (an erasure of the client touches all of them) */
    public function clientOrMattersHeld(int $clientId): bool
    {
        if ($this->clientHeld($clientId)) {
            return true;
        }
        $cases = DB::table('cases')->where('client_id', $clientId)->pluck('id');

        return $cases->isNotEmpty() && DB::table('legal_holds')->where('scope_type', 'case')->whereIn('scope_id', $cases)->whereNull('released_at')->exists();
    }

    /** @return list<int> ids of matters that are held (directly or through their client) */
    public function heldCaseIds(): array
    {
        $direct = DB::table('legal_holds')->where('scope_type', 'case')->whereNull('released_at')->pluck('scope_id');
        $clients = DB::table('legal_holds')->where('scope_type', 'client')->whereNull('released_at')->pluck('scope_id');
        $viaClient = $clients->isEmpty() ? collect() : DB::table('cases')->whereIn('client_id', $clients)->pluck('id');

        return $direct->merge($viaClient)->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /** @return list<int> clients under a hold of their own (a hold on one of their matters is in heldCaseIds()) */
    public function heldClientIds(): array
    {
        return DB::table('legal_holds')->where('scope_type', 'client')->whereNull('released_at')->pluck('scope_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    public function held(Model $m): bool
    {
        return match (true) {
            $m instanceof Client => $this->clientHeld((int) $m->getKey()),
            $m instanceof Cases => $this->caseHeld((int) $m->getKey()),
            $m instanceof Document => $this->caseHeld((int) $m->case_id),
            default => false,
        };
    }

    /** The model `deleting` guard: soft deletes, force deletes and bulk loops all pass through it. */
    public function guard(Model $m): void
    {
        if ($this->held($m)) {
            $e = new LegalHoldException('This record is under a legal hold and cannot be deleted. Release the hold first.');
            if ($m->getConnection()->transactionLevel() > 0) {
                $by = Auth::id();
                throw $e->deferAudit(fn () => $this->audit($m, $by));   // written after the rollback
            }
            $this->audit($m, Auth::id());

            throw $e;
        }
    }

    private function audit(Model $m, int|string|null $by): void
    {
        try {
            SecurityPolicy::audit('legal_hold_blocked_delete', class_basename($m), (int) $m->getKey(), [], ['by' => $by]);
        } catch (\Throwable) {
            // the refusal must stand even if the audit write fails
        }
    }
}
