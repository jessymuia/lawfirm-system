<?php

namespace App\Actions\Matters;

use App\Actions\ActionException;
use App\Actions\Concerns\Stamps;
use App\Models\Cases;
use App\Models\User;

/** ENG-9 (FUN-6): move a matter along its lifecycle; only the transitions of MatterStatus (DATA-12) are allowed. */
class ChangeMatterStatus
{
    use Stamps;

    public function handle(Cases $case, string $status, ?User $actor): Cases
    {
        if (! $case->canTransitionTo($status)) {
            throw new ActionException("Cannot move a case from '{$case->lifecycle_status}' to '{$status}'");
        }
        $case->lifecycle_status = $status;
        $this->stamp($case, $actor)->save();

        return $case;
    }
}
