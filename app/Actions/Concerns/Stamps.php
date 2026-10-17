<?php

namespace App\Actions\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ENG-9: who did it. Actions take the acting person explicitly (null for the system: a job, the scheduler, an import),
 * so the same operation records the same trail whether it came from a request, the console or a queue.
 */
trait Stamps
{
    protected function stamp(Model $model, ?User $actor, string $column = 'updated_by'): Model
    {
        if ($actor) {
            $model->forceFill([$column => $actor->getKey()]);
        }

        return $model;
    }

    /** Soft-deletes the way every screen does: who deleted it is recorded first. */
    protected function softDelete(Model $model, ?User $actor): void
    {
        $this->stamp($model, $actor, 'deleted_by')->save();
        $model->delete();
    }
}
