<?php

namespace App\Compliance\RecordClasses;

use Illuminate\Support\Collection;

/**
 * SEC-13: one kind of record the retention schedule can dispose of (config compliance.classes.*.handler). A class says
 * which records are past their period, and disposes of one. It never lists a record under legal hold, and checks the
 * hold again immediately before disposing (a hold placed in between wins: it throws LegalHoldException).
 */
interface RecordClass
{
    /** The record_type written to retention_disposals. */
    public function type(): string;

    /**
     * Records whose retention clock ended on or before $cutoff and that have not been disposed of yet.
     *
     * @param  list<int>  $heldCaseIds  matters under hold (directly or through their client)
     * @return array{due: Collection<int, int>, held: int} ids due, and how many were left out because of a hold
     */
    public function due(string $cutoff, array $heldCaseIds): array;

    /** @return array<string, mixed> what was disposed of, for the audit entry (no personal data) */
    public function dispose(int $id, int $years, ?int $actor): array;
}
