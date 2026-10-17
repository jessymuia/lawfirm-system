<?php

namespace App\Compliance;

use Closure;
use RuntimeException;

/**
 * Thrown when something under a legal hold is about to be deleted, disposed of or erased. Rendered as 423 Locked.
 *
 * The blocked attempt is audited. Inside a transaction (an action deleting several records all or nothing, ENG-9) an
 * audit row written there would roll back with everything else, so it travels with the exception instead and is
 * written once the transaction is gone (when the refusal is rendered).
 */
class LegalHoldException extends RuntimeException
{
    private ?Closure $pendingAudit = null;

    public function deferAudit(Closure $write): static
    {
        $this->pendingAudit = $write;

        return $this;
    }

    public function recordAudit(): void
    {
        $write = $this->pendingAudit;
        $this->pendingAudit = null;
        $write && $write();
    }
}
