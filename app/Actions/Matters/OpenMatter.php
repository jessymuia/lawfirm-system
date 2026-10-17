<?php

namespace App\Actions\Matters;

use App\Actions\ActionException;
use App\Conflicts\ConflictChecks;
use App\Conflicts\ConflictException;
use App\Models\Cases;
use App\Models\MatterParty;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: open a matter. It is opened against a cleared conflict check when one is given or the firm requires it (FUN-9),
 * belongs to its responsible lawyer's office and practice group unless told otherwise (FUN-18), and its opposing party
 * joins the conflicts register.
 *
 * @phpstan-type MatterData array{case_number: string, description: string, client_id: int, assigned_to: int, start_date: string,
 *     end_date?: ?string, police_station: string, court_id: int, opposing_party: string, case_type: string,
 *     client_matter_id?: ?string, office_id?: ?int, practice_group_id?: ?int}
 */
class OpenMatter
{
    public function __construct(private ConflictChecks $conflicts) {}

    /** @param array<string, mixed> $data dates as Y-m-d; office_id / practice_group_id only when chosen (null = none) */
    public function handle(array $data, ?User $actor, ?int $conflictCheckId = null): Cases
    {
        try {
            return DB::transaction(function () use ($data, $actor, $conflictCheckId) {
                $check = $actor
                    ? $this->conflicts->clearanceFor('matter', $conflictCheckId, (string) $data['opposing_party'], $actor)
                    : (ConflictChecks::required() ? throw new ConflictException('Run a conflict check for this matter first.', 422, 'conflict_check_required') : null);

                $lawyer = User::whereKey($data['assigned_to'])->first(['office_id', 'practice_group_id']);
                $case = new Cases;
                $case->fill([
                    'case_number' => $data['case_number'], 'description' => $data['description'], 'client_id' => $data['client_id'],
                    'assigned_to' => $data['assigned_to'], 'start_date' => $data['start_date'], 'end_date' => $data['end_date'] ?? null,
                    'police_station' => $data['police_station'], 'court_id' => $data['court_id'], 'opposing_party' => $data['opposing_party'],
                    'case_type' => $data['case_type'], 'client_matter_id' => $data['client_matter_id'] ?? null,
                    'office_id' => array_key_exists('office_id', $data) ? $data['office_id'] : $lawyer?->office_id,
                    'practice_group_id' => array_key_exists('practice_group_id', $data) ? $data['practice_group_id'] : $lawyer?->practice_group_id,
                ]);
                $actor && $case->forceFill(['created_by' => $actor->getKey()]);
                $case->save();

                MatterParty::syncOpposingParty($case);
                $this->conflicts->link($check, 'matter', (int) $case->id);

                return $case;
            });
        } catch (ConflictException $e) {
            throw new ActionException($e->getMessage(), $e->status, $e->code_);
        }
    }
}
