<?php

namespace App\Actions\Matters;

use App\Actions\Concerns\Stamps;
use App\Models\Cases;
use App\Models\MatterParty;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** ENG-9: change a matter's details. Fields left out stay as they are; the opposing party is kept in the register. */
class UpdateMatter
{
    use Stamps;

    public const FIELDS = ['case_number', 'description', 'client_id', 'assigned_to', 'start_date', 'end_date', 'police_station', 'court_id',
        'opposing_party', 'case_type', 'client_matter_id', 'office_id', 'practice_group_id'];

    /** @param array<string, mixed> $data only the fields to change (dates as Y-m-d) */
    public function handle(Cases $case, array $data, ?User $actor): Cases
    {
        return DB::transaction(function () use ($case, $data, $actor) {
            $case->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $this->stamp($case, $actor)->save();
            MatterParty::syncOpposingParty($case);

            return $case;
        });
    }
}
