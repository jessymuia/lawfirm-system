<?php

namespace App\Conflicts;

use App\Models\User;
use App\Support\FirmSettings;
use Illuminate\Support\Facades\DB;

/**
 * FUN-9: the firm's record of conflict checks, and the rule that ties a new client or matter to a cleared check.
 *
 * A check is good for opening a record when it was run by the same person in the last CHECK_HOURS, has not been used
 * for another record, covers the name actually being opened, and is either clear or has a recorded decision to proceed
 * (which needs manage-conflicts and a reason). With the firm policy `conflicts.required` on, opening a client or
 * matter without one is refused; with it off, a check that is supplied is still validated and linked.
 */
class ConflictChecks
{
    public const CHECK_HOURS = 24;

    public function __construct(private ConflictSearch $search) {}

    public static function required(): bool
    {
        return (bool) FirmSettings::get('conflicts.required', false);
    }

    /** @param list<array{name: string, role: string}> $names */
    public function run(string $purpose, array $names, ?string $phone, ?string $identifier, User $by, ?int $excludeCaseId = null): object
    {
        $r = $this->search->search($names, $phone, $identifier, $by, $excludeCaseId);
        $id = DB::table('conflict_checks')->insertGetId([
            'purpose' => $purpose,
            'query' => json_encode(['names' => $names, 'phone' => $phone, 'identifier' => $identifier, 'exclude_case_id' => $excludeCaseId]),
            'hits' => json_encode($r['hits']),
            'outcome' => $r['outcome'],
            'created_by' => $by->getKey(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->find($id);
    }

    public function decide(int $id, string $decision, ?string $note, User $by): object
    {
        $c = $this->find($id) ?? throw new ConflictException('No such conflict check.', 404);
        if ($c->subject_id) {
            throw new ConflictException('This check has already been used to open a record.', 409);
        }
        DB::table('conflict_checks')->where('id', $id)->update([
            'decision' => $decision, 'decision_note' => $note, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'updated_at' => now(),
        ]);

        return $this->find($id);
    }

    /**
     * Before creating a client/matter: the check that clears it (null when none is given and none is required).
     * The caller links it with link() once the record exists, in the same transaction.
     */
    public function clearanceFor(string $subject, ?int $checkId, string $name, User $by): ?object
    {
        if (! $checkId) {
            if (self::required()) {
                throw new ConflictException('Run a conflict check for this '.($subject === 'client' ? 'client' : 'matter').' first.', 422, 'conflict_check_required');
            }

            return null;
        }
        $c = DB::table('conflict_checks')->where('id', $checkId)->lockForUpdate()->first();
        if (! $c || (int) $c->created_by !== (int) $by->getKey()) {
            throw new ConflictException('That conflict check was not found.', 422, 'conflict_check_invalid');
        }
        if ($c->subject_id) {
            throw new ConflictException('That conflict check was already used. Run a new one.', 422, 'conflict_check_used');
        }
        if (now()->subHours(self::CHECK_HOURS)->gt($c->created_at)) {
            throw new ConflictException('That conflict check is more than '.self::CHECK_HOURS.' hours old. Run it again.', 422, 'conflict_check_stale');
        }
        $covered = collect(json_decode($c->query, true)['names'] ?? [])->contains(fn ($n) => Names::normalize($n['name'] ?? '') === Names::normalize($name));
        if (! $covered) {
            throw new ConflictException('The conflict check was run for a different name. Run it again for "'.$name.'".', 422, 'conflict_check_mismatch');
        }
        if ($c->outcome === 'potential' && $c->decision !== 'proceed') {
            throw new ConflictException($c->decision === 'declined'
                ? 'This intake was declined after the conflict check.'
                : 'The conflict check found possible conflicts. A conflicts administrator must record a decision to proceed.', 422, 'conflict_unresolved');
        }

        return $c;
    }

    public function link(?object $check, string $subject, int $id): void
    {
        if ($check) {
            DB::table('conflict_checks')->where('id', $check->id)->whereNull('subject_id')
                ->update(['subject_type' => $subject, 'subject_id' => $id, 'updated_at' => now()]);
        }
    }

    public function find(int $id): ?object
    {
        $c = DB::table('conflict_checks')->where('id', $id)->first();
        if ($c) {
            $c->query = json_decode($c->query, true);
            $c->hits = json_decode($c->hits, true);
        }

        return $c;
    }
}
