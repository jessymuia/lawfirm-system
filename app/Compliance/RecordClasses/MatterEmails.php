<?php

namespace App\Compliance\RecordClasses;

use App\Compliance\LegalHoldException;
use App\Compliance\LegalHolds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SEC-13: emails filed to closed or settled matters (FUN-15). Disposal deletes the original .eml file and empties the
 * message's addresses, subject and body; the row stays as a stub (date, matter) so the matter's history shows that an
 * email was there. Attachments are documents and follow the documents class.
 */
class MatterEmails implements RecordClass
{
    use ClosedMatters;

    public function __construct(private LegalHolds $holds) {}

    public function type(): string
    {
        return 'matter_email';
    }

    public function due(string $cutoff, array $heldCaseIds): array
    {
        $base = DB::table('matter_emails')->whereIn('case_id', $this->endedBy($cutoff))->whereNotIn('id', $this->disposed());

        return ['held' => (clone $base)->whereIn('case_id', $heldCaseIds)->count(),
            'due' => (clone $base)->when($heldCaseIds, fn ($q) => $q->whereNotIn('case_id', $heldCaseIds))->orderBy('id')->pluck('id')];
    }

    public function dispose(int $id, int $years, ?int $actor): array
    {
        $email = DB::table('matter_emails')->where('id', $id)->first() ?? throw new \RuntimeException("Email {$id} not found.");
        if ($this->holds->caseHeld((int) $email->case_id)) {
            throw new LegalHoldException('held');
        }
        $storage = Storage::disk($email->raw_disk);
        $storage->exists($email->raw_path) && $storage->delete($email->raw_path);
        if ($storage->exists($email->raw_path)) {
            throw new \RuntimeException("The original of email {$id} could not be removed.");
        }
        DB::transaction(function () use ($email, $years, $actor) {
            DB::table('matter_emails')->where('id', $email->id)->update(['from_email' => null, 'from_name' => null, 'to_list' => null, 'cc_list' => null,
                'subject' => '[disposed under the retention schedule]', 'body_text' => null, 'skipped_attachments' => null, 'updated_at' => now()]);
            DB::table('retention_disposals')->insert(['record_type' => $this->type(), 'record_id' => $email->id, 'case_id' => $email->case_id,
                'record_class' => 'matter_emails', 'retain_years' => $years, 'file_deleted' => true, 'disposed_by' => $actor,
                'disposed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });

        return ['sent_at' => $email->sent_at, 'file_deleted' => true];
    }
}
