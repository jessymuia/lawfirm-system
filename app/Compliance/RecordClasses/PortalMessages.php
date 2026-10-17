<?php

namespace App\Compliance\RecordClasses;

use App\Compliance\LegalHolds;
use App\Compliance\LegalHoldException;
use Illuminate\Support\Facades\DB;

/** SEC-13: client-portal messages (FUN-10) of closed or settled matters. Disposal deletes the message. */
class PortalMessages implements RecordClass
{
    use ClosedMatters;

    public function __construct(private LegalHolds $holds) {}

    public function type(): string
    {
        return 'portal_message';
    }

    public function due(string $cutoff, array $heldCaseIds): array
    {
        $base = DB::table('portal_messages')->whereIn('case_id', $this->endedBy($cutoff));

        return ['held' => (clone $base)->whereIn('case_id', $heldCaseIds)->count(),
            'due' => (clone $base)->when($heldCaseIds, fn ($q) => $q->whereNotIn('case_id', $heldCaseIds))->orderBy('id')->pluck('id')];
    }

    public function dispose(int $id, int $years, ?int $actor): array
    {
        $m = DB::table('portal_messages')->where('id', $id)->first() ?? throw new \RuntimeException("Message {$id} not found.");
        if ($this->holds->caseHeld((int) $m->case_id)) {
            throw new LegalHoldException('held');
        }
        DB::transaction(function () use ($m, $years, $actor) {
            DB::table('portal_messages')->where('id', $m->id)->delete();
            DB::table('retention_disposals')->insert(['record_type' => $this->type(), 'record_id' => $m->id, 'case_id' => $m->case_id,
                'record_class' => 'portal_messages', 'retain_years' => $years, 'file_deleted' => false, 'disposed_by' => $actor,
                'disposed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return ['direction' => $m->direction, 'sent_at' => $m->created_at];
    }
}
