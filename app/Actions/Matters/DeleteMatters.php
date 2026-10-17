<?php

namespace App\Actions\Matters;

use App\Actions\Concerns\Stamps;
use App\Models\Cases;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: delete matters (soft). All or nothing: one matter that refuses (a legal hold, SEC-13) refuses the whole batch
 * rather than leaving it half done.
 */
class DeleteMatters
{
    use Stamps;

    /** @param iterable<Cases> $cases @return Collection<int, int> the ids deleted */
    public function handle(iterable $cases, ?User $actor): Collection
    {
        return DB::transaction(function () use ($cases, $actor) {
            $ids = collect();
            foreach ($cases as $case) {
                $this->softDelete($case, $actor);
                $ids->push((int) $case->id);
            }

            return $ids;
        });
    }

    public function restore(Cases $case): Cases
    {
        $case->restore();
        $case->forceFill(['deleted_by' => null])->save();

        return $case;
    }
}
