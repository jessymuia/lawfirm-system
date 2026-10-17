<?php

namespace App\Conflicts;

use App\Matters\MatterAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FUN-9: who in the firm's records could conflict with the names being taken on. The search runs across the WHOLE
 * firm (every client and every matter's parties, closed matters and former clients included, since acting against a
 * former client is the classic conflict). It deliberately reads past matter access and ethical walls, because a
 * conflict check that cannot see a matter is worthless. What it SHOWS is limited instead: a hit on a matter the person may not
 * open says only that a conflicting record exists, so they take it to a conflicts administrator.
 *
 * Each hit is classified from the two roles involved:
 *  - conflict:  we would act for someone we are already against (new client = existing adverse party), or against
 *               someone we act for (new adverse party = existing client);
 *  - related:   the name appears as a co-party, related party or opposing counsel elsewhere;
 *  - same_side: already against them on another matter (worth knowing, not a conflict);
 *  - existing_client: the new client is (probably) an existing client.
 * The outcome is `potential` when anything is a conflict or related, otherwise `clear`.
 */
class ConflictSearch
{
    public const CANDIDATES = 400;

    /**
     * @param  list<array{name: string, role: string}>  $names  role: client | adverse | co_party | related | opposing_counsel
     * @return array{outcome: string, hits: list<array<string, mixed>>}
     */
    public function search(array $names, ?string $phone, ?string $identifier, User $viewer, ?int $excludeCaseId = null): array
    {
        $hits = [];
        foreach ($names as $n) {
            $norm = Names::normalize($n['name']);
            if ($norm === '') {
                continue;
            }
            foreach ($this->clientCandidates($norm) as $c) {
                $score = Names::score($norm, Names::normalize($c->name));
                if ($score > 0) {
                    $hits[] = $this->clientHit($c, $n, $score, 'name', $viewer);
                }
            }
            foreach ($this->partyCandidates($norm, $excludeCaseId) as $p) {
                $score = Names::score($norm, $p->name_normalized);
                if ($score > 0) {
                    $hits[] = $this->partyHit($p, $n, $score, 'name', $viewer);
                }
            }
        }

        $primary = collect($names)->firstWhere('role', 'client') ?? ($names[0] ?? null);
        if ($primary && ($ph = Names::phone($phone)) !== '') {
            // stored numbers are free text ("0712 345 678", "+254-712..."): compare digits only, by their last nine
            $digits = fn (string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$col}, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
            foreach (DB::table('clients')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereRaw($digits('phone_number').' LIKE ?', ['%'.$ph])->orWhereRaw($digits('extra_phone_number').' LIKE ?', ['%'.$ph]))
                ->limit(20)->get() as $c) {
                $hits[] = $this->clientHit($c, $primary, 1.0, 'phone', $viewer);
            }
        }
        if ($primary && ($id = Names::identifier($identifier)) !== '') {
            foreach (DB::table('matter_parties')->whereNull('deleted_at')->where('identifier_normalized', $id)
                ->when($excludeCaseId, fn ($q) => $q->where('case_id', '!=', $excludeCaseId))->limit(20)->get() as $p) {
                $hits[] = $this->partyHit($p, $primary, 1.0, 'identifier', $viewer);
            }
        }

        $hits = $this->dedupe($hits);
        $potential = collect($hits)->contains(fn ($h) => in_array($h['kind'], ['conflict', 'related'], true));

        return ['outcome' => $potential ? 'potential' : 'clear', 'hits' => $hits];
    }

    /** Pre-filter in SQL by any meaningful word, then score in PHP: bounded work whatever the firm's size. */
    private function clientCandidates(string $norm)
    {
        return DB::table('clients')->whereNull('deleted_at')
            ->where(function ($q) use ($norm) {
                foreach ($this->words($norm) as $w) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$w.'%']);
                }
            })->limit(self::CANDIDATES)->get(['id', 'name', 'phone_number']);
    }

    private function partyCandidates(string $norm, ?int $excludeCaseId)
    {
        return DB::table('matter_parties')->whereNull('deleted_at')
            ->when($excludeCaseId, fn ($q) => $q->where('case_id', '!=', $excludeCaseId))
            ->where(function ($q) use ($norm) {
                foreach ($this->words($norm) as $w) {
                    $q->orWhere('name_normalized', 'like', '%'.$w.'%');
                }
            })->limit(self::CANDIDATES)->get(['id', 'case_id', 'role', 'name', 'name_normalized']);
    }

    /** The words worth searching on; a name made only of short words is searched whole. @return list<string> */
    private function words(string $norm): array
    {
        $words = array_values(array_filter(explode(' ', $norm), fn ($w) => strlen($w) >= 3));

        return $words ?: [$norm];
    }

    private function clientHit(object $c, array $searched, float $score, string $reason, User $viewer): array
    {
        // a client is "accessible" when the viewer can see at least one of their matters, or they have none
        $caseIds = DB::table('cases')->whereNull('deleted_at')->where('client_id', $c->id)->pluck('id');
        $accessible = $caseIds->isEmpty() || $caseIds->contains(fn ($id) => MatterAccess::canAccessCase($viewer, (int) $id));
        $kind = match ($searched['role']) {
            'client' => 'existing_client',
            'adverse' => 'conflict',
            default => 'related',
        };

        return [
            'kind' => $kind, 'reason' => $reason, 'score' => $score,
            'searched' => ['name' => $searched['name'], 'role' => $searched['role']],
            'found' => ['source' => 'client', 'role' => 'client', 'name' => $accessible ? $c->name : null, 'client_id' => $accessible ? $c->id : null],
            'matter' => null, 'accessible' => $accessible,
            'key' => 'client:'.$c->id.':'.$searched['role'],
        ];
    }

    private function partyHit(object $p, array $searched, float $score, string $reason, User $viewer): array
    {
        $case = DB::table('cases')->whereNull('deleted_at')->where('id', $p->case_id)->first(['id', 'case_number', 'client_id', 'lifecycle_status']);
        $accessible = $case && MatterAccess::canAccessCase($viewer, (int) $case->id);
        $kind = match (true) {
            $searched['role'] === 'client' && $p->role === 'adverse' => 'conflict',
            $searched['role'] === 'adverse' && $p->role === 'adverse' => 'same_side',
            default => 'related',
        };

        return [
            'kind' => $kind, 'reason' => $reason, 'score' => $score,
            'searched' => ['name' => $searched['name'], 'role' => $searched['role']],
            'found' => ['source' => 'party', 'role' => $p->role, 'name' => $accessible ? $p->name : null],
            'matter' => $case ? [
                'id' => $accessible ? $case->id : null,
                'case_number' => $accessible ? $case->case_number : null,
                'closed' => in_array($case->lifecycle_status ?? null, ['closed', 'settled'], true),
            ] : null,
            'accessible' => $accessible,
            'key' => 'party:'.$p->case_id.':'.$p->role.':'.$searched['role'].':'.$p->name_normalized,
        ];
    }

    /** One hit per record and searched role, keeping the strongest; strongest first. @return list<array<string, mixed>> */
    private function dedupe(array $hits): array
    {
        $best = [];
        foreach ($hits as $h) {
            if (! isset($best[$h['key']]) || $h['score'] > $best[$h['key']]['score']) {
                $best[$h['key']] = $h;
            }
        }
        $order = ['conflict' => 0, 'related' => 1, 'existing_client' => 2, 'same_side' => 3];
        usort($best, fn ($a, $b) => [$order[$a['kind']], -$a['score']] <=> [$order[$b['kind']], -$b['score']]);

        return array_map(function ($h) {
            unset($h['key']);

            return $h;
        }, $best);
    }
}
