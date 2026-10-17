<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * ENG-11: invoices. Deleting has an object-level rule: holders of delete-invoice may delete any invoice they can
 * reach; anyone else only their own draft. A refusal answers 404, never revealing that someone else's invoice exists.
 */
class InvoicePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::DELETE_INVOICES);
    }

    public function delete(User $user, Model $record): Response
    {
        if (! $this->reachable($user, $record)) {
            return Response::denyAsNotFound();
        }
        $ownDraft = (int) $record->getAttribute('created_by') === (int) $user->getKey() && $record->getAttribute('workflow_status') === 'draft';

        return $this->has($user, 'delete') || $ownDraft ? Response::allow() : Response::denyAsNotFound();
    }

    /** May this person delete invoices at all (any invoice, or drafts of their own)? Rows are then checked one by one. */
    public function deleteAny(User $user): bool
    {
        return $this->has($user, 'delete') || $this->has($user, 'create');
    }
}
