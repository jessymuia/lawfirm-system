<?php

namespace App\Email;

use App\Actions\Documents\StoreDocument;
use App\Http\Requests\Document\StoreRequest as DocumentRules;
use App\Models\Cases;
use App\Security\SecurityPolicy;
use App\Support\FirmSettings;
use App\Tenancy\FirmStorage;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FUN-15: files an email to a matter: the message (text, people, date, thread), the original .eml as evidence, and its
 * attachments as ordinary matter documents. A message filed to the same matter twice (forwarded by two people, sent to
 * the address and uploaded) is kept once.
 *
 * Inbound mail is accepted only from the firm's own people by default (firm setting email_ingest_senders: staff |
 * firm_domains | anyone), so a leaked address cannot be used to put documents into a matter.
 */
class EmailFiling
{
    public const SENDER_POLICIES = ['staff', 'firm_domains', 'anyone'];

    /** The matter's filing address, made on first use; null when the deployment has no inbound domain. */
    public function addressFor(Cases $case): ?string
    {
        $domain = $this->domain();
        if (! $domain) {
            return null;
        }
        if (! $case->email_token) {
            $case->forceFill(['email_token' => Str::lower(Str::random(24))])->saveQuietly();
        }

        return "matter-{$case->email_token}@{$domain}";
    }

    /** The matters a message was addressed to (by their filing addresses among the recipients). @return list<int> */
    public function casesFor(array $recipients): array
    {
        $domain = $this->domain();
        $tokens = [];
        foreach ($recipients as $r) {
            if ($domain && preg_match('/^matter-([a-z0-9]{24})@(.+)$/i', trim((string) $r), $m) && strcasecmp($m[2], $domain) === 0) {
                $tokens[] = strtolower($m[1]);
            }
        }

        return $tokens ? DB::table('cases')->whereIn('email_token', array_unique($tokens))->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    }

    public function senderAllowed(?string $from): bool
    {
        $policy = FirmSettings::get('email_ingest_senders', 'staff');
        if ($policy === 'anyone') {
            return true;
        }
        $from = strtolower(trim((string) $from));
        if ($from === '') {
            return false;
        }
        $staff = DB::table('users')->whereNull('deleted_at')->pluck('email')->map(fn ($e) => strtolower((string) $e));
        if ($staff->contains($from)) {
            return true;
        }

        return $policy === 'firm_domains' && $staff->map(fn ($e) => substr((string) strrchr($e, '@'), 1))->unique()->contains(substr((string) strrchr($from, '@'), 1));
    }

    /**
     * @return array{id: int, duplicate: bool}
     */
    public function file(int $caseId, string $raw, string $source, ?int $by = null): array
    {
        $msg = MimeMessage::parse($raw);
        $messageId = $msg->messageId() ?? 'sha256:'.hash('sha256', $raw);
        $existing = DB::table('matter_emails')->where('case_id', $caseId)->where('message_id', $messageId)->value('id');
        if ($existing) {
            return ['id' => (int) $existing, 'duplicate' => true];
        }

        $disk = FirmStorage::disk();
        $path = app(TenantContext::class)->storagePrefix('emails/'.$caseId).'/'.Str::random(24).'.eml';
        Storage::disk($disk)->put($path, $raw);

        return DB::transaction(function () use ($msg, $messageId, $caseId, $raw, $source, $by, $disk, $path) {
            $from = $msg->addresses('from')[0] ?? null;
            $skipped = [];
            $docs = [];
            foreach ($msg->attachments as $a) {
                $why = $this->refuse($a);
                if ($why) {
                    $skipped[] = ['filename' => $a['filename'], 'reason' => $why];

                    continue;
                }
                $docs[] = $this->document($caseId, $a, $msg->subject());
            }
            $id = DB::table('matter_emails')->insertGetId([
                'case_id' => $caseId, 'message_id' => $messageId, 'thread_key' => $msg->threadKey(),
                'from_email' => $from['email'] ?? null, 'from_name' => $from['name'] ?? null,
                'to_list' => json_encode($msg->addresses('to')), 'cc_list' => json_encode($msg->addresses('cc')),
                'subject' => mb_substr($msg->subject(), 0, 500), 'sent_at' => $msg->date()?->utc(),
                'body_text' => mb_substr($msg->text, 0, 2_000_000), 'had_html' => $msg->hadHtml,
                'skipped_attachments' => $skipped ? json_encode($skipped) : null,
                'raw_disk' => $disk, 'raw_path' => $path, 'raw_sha256' => hash('sha256', $raw), 'raw_size' => strlen($raw),
                'source' => $source, 'filed_by' => $by, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($docs as $docId) {
                DB::table('matter_email_attachments')->insert(['matter_email_id' => $id, 'document_id' => $docId, 'created_at' => now(), 'updated_at' => now()]);
            }
            SecurityPolicy::audit('email_filed', 'matter_email', $id, [], ['case_id' => $caseId, 'source' => $source, 'from' => $from['email'] ?? null, 'attachments' => count($docs)]);

            return ['id' => $id, 'duplicate' => false];
        });
    }

    /** Attachments follow the same rules as uploads: known document types, within the size limit. */
    private function refuse(array $a): ?string
    {
        if (strlen($a['content']) > DocumentRules::MAX_FILE_SIZE_KB * 1024) {
            return 'too large';
        }
        if (! in_array($a['mime'], DocumentRules::ALLOWED_MIME_TYPES, true)) {
            // mail clients often label documents application/octet-stream: trust the content, not the label
            $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->buffer($a['content']) ?: '';
            if (! in_array($sniffed, DocumentRules::ALLOWED_MIME_TYPES, true)) {
                return 'file type not accepted';
            }
        }

        return null;
    }

    private function document(int $caseId, array $a, string $subject): int
    {
        $mime = in_array($a['mime'], DocumentRules::ALLOWED_MIME_TYPES, true) ? $a['mime'] : ((new \finfo(FILEINFO_MIME_TYPE))->buffer($a['content']) ?: $a['mime']);
        $doc = app(StoreDocument::class)->handle($caseId, $a['content'], [
            'title' => mb_substr(pathinfo($a['filename'], PATHINFO_FILENAME).($subject !== '' ? ' (email: '.$subject.')' : ''), 0, 255),
            'filename' => $a['filename'], 'mimetype' => $mime,
        ], null);

        return (int) $doc->id;
    }

    private function domain(): ?string
    {
        $d = trim((string) config('email_ingest.domain'));

        return $d === '' ? null : strtolower(str_replace('{firm}', app(TenantContext::class)->key(), $d));
    }
}
