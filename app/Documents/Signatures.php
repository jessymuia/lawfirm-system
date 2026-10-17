<?php

namespace App\Documents;

use App\Actions\Documents\StoreDocument;
use App\Jobs\SendSmsJob;
use App\Mail\SignatureRequested;
use App\Models\Document;
use App\Security\SecurityPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FUN-14: electronic signature of a matter document.
 *
 * Each signer gets a personal secret link (by email and/or SMS). They open the exact file that was sent, type their
 * name, draw their signature and confirm their intent to sign. The evidence kept for each signature: who, when (UTC),
 * from where (IP, browser), what they saw first, and the SHA-256 of the document, which is checked again at the moment
 * of signing so nobody signs a file that changed. When everyone has signed, a signature certificate (PDF) with that
 * evidence is filed next to the document. This is a simple electronic signature with an audit trail; for documents
 * the law requires an advanced or witnessed signature for, use those instead (see docs/DOCUMENT_ASSEMBLY.md).
 */
class Signatures
{
    public const LINK_DAYS = 14;

    /** @param list<array{name: string, email: ?string, phone: ?string}> $signers @return int the request id */
    public function create(Document $doc, string $title, ?string $message, array $signers, bool $inOrder, int $days, int $by, string $host): int
    {
        $sha = $this->sha($doc) ?? throw new \RuntimeException('The document file is missing.');

        return DB::transaction(function () use ($doc, $title, $message, $signers, $inOrder, $days, $by, $sha, $host) {
            $id = DB::table('signature_requests')->insertGetId([
                'case_id' => $doc->case_id, 'document_id' => $doc->id, 'title' => $title, 'message' => $message, 'document_sha256' => $sha,
                'in_order' => $inOrder, 'expires_at' => now()->addDays($days), 'created_by' => $by, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($signers as $i => $s) {
                $signerId = DB::table('signature_signers')->insertGetId([
                    'signature_request_id' => $id, 'position' => $i + 1, 'name' => $s['name'], 'email' => $s['email'] ?? null, 'phone' => $s['phone'] ?? null,
                    'token_hash' => hash('sha256', Str::random(48)), 'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! $inOrder || $i === 0) {
                    $this->notify($signerId, $host);
                }
            }
            SecurityPolicy::audit('signature_requested', 'signature_request', $id, [], ['document_id' => $doc->id, 'sha256' => $sha, 'signers' => count($signers)]);

            return $id;
        });
    }

    /** Sends a signer a new personal link (a new link replaces the previous one). */
    public function notify(int $signerId, string $host): void
    {
        $s = DB::table('signature_signers')->where('id', $signerId)->first();
        $r = DB::table('signature_requests')->where('id', $s->signature_request_id)->first();
        $token = Str::random(48);
        DB::table('signature_signers')->where('id', $s->id)->update(['token_hash' => hash('sha256', $token), 'notified_at' => now(), 'updated_at' => now()]);
        $link = rtrim($host, '/').'/sign/'.$token;
        $firm = (string) config('app.name');
        // sent once the surrounding transaction has committed: a link must never point at a request that was rolled back
        if ($s->email) {
            Mail::to($s->email)->queue((new SignatureRequested($firm, $s->name, $r->title, $r->message, $link,
                \Carbon\CarbonImmutable::parse($r->expires_at)->format('j F Y')))->afterCommit());
        }
        if ($s->phone) {
            SendSmsJob::dispatch((string) $s->phone, "{$firm} asks you to sign \"".Str::limit($r->title, 60)."\": {$link}")->afterCommit();
        }
    }

    /** @return array{signer: object, request: object}|null the signer behind a link, if it can still be used */
    public function open(string $token): ?array
    {
        $s = DB::table('signature_signers')->where('token_hash', hash('sha256', $token))->first();
        if (! $s) {
            return null;
        }
        $r = DB::table('signature_requests')->where('id', $s->signature_request_id)->first();
        if ($r->state === 'pending' && now()->gte($r->expires_at)) {
            DB::table('signature_requests')->where('id', $r->id)->update(['state' => 'expired', 'updated_at' => now()]);
            $r->state = 'expired';
        }

        return ['signer' => $s, 'request' => $r];
    }

    /** Whether it is this signer's turn (in-order requests wait for everyone before them). */
    public function isTurn(object $r, object $s): bool
    {
        return ! $r->in_order || ! DB::table('signature_signers')->where('signature_request_id', $r->id)
            ->where('position', '<', $s->position)->where('state', '!=', 'signed')->exists();
    }

    public function viewed(object $s): void
    {
        if (! $s->viewed_at) {
            DB::table('signature_signers')->where('id', $s->id)->update(['viewed_at' => now(), 'state' => $s->state === 'pending' ? 'viewed' : $s->state, 'updated_at' => now()]);
        }
    }

    /** @return string|null why it cannot be signed, or null when it was signed */
    public function sign(string $token, string $typedName, string $png, ?string $ip, ?string $agent, string $host): ?string
    {
        return DB::transaction(function () use ($token, $typedName, $png, $ip, $agent, $host) {
            $o = $this->open($token);
            if (! $o) {
                return 'This signing link is not valid.';
            }
            $s = DB::table('signature_signers')->where('id', $o['signer']->id)->lockForUpdate()->first();
            $r = $o['request'];
            if ($r->state !== 'pending' || in_array($s->state, ['signed', 'declined'], true)) {
                return 'This document can no longer be signed through this link.';
            }
            if (! $this->isTurn($r, $s)) {
                return 'Someone else signs before you. You will get a link when it is your turn.';
            }
            $doc = Document::withoutGlobalScopes()->find($r->document_id);
            if (! $doc || $this->sha($doc) !== $r->document_sha256) {
                return 'The document has changed since it was sent, so it cannot be signed. Please contact the firm.';
            }
            DB::table('signature_signers')->where('id', $s->id)->update([
                'state' => 'signed', 'signed_at' => now(), 'signed_name' => $typedName, 'signature_image' => $png,
                'viewed_at' => $s->viewed_at ?? now(), 'ip_address' => $ip, 'user_agent' => Str::limit((string) $agent, 480, ''), 'updated_at' => now(),
            ]);
            SecurityPolicy::audit('document_signed', 'signature_request', (int) $r->id, [], ['signer_id' => (int) $s->id, 'name' => $typedName, 'sha256' => $r->document_sha256]);

            $next = DB::table('signature_signers')->where('signature_request_id', $r->id)->where('state', '!=', 'signed')->orderBy('position')->first();
            if (! $next) {
                $this->complete((int) $r->id);
            } elseif ($r->in_order && ! $next->notified_at) {
                $this->notify((int) $next->id, $host);
            }

            return null;
        });
    }

    public function decline(string $token, string $reason, ?string $ip): ?string
    {
        $o = $this->open($token);
        if (! $o || $o['request']->state !== 'pending' || in_array($o['signer']->state, ['signed', 'declined'], true)) {
            return 'This document can no longer be declined through this link.';
        }
        DB::table('signature_signers')->where('id', $o['signer']->id)->update(['state' => 'declined', 'decline_reason' => $reason, 'ip_address' => $ip, 'updated_at' => now()]);
        DB::table('signature_requests')->where('id', $o['request']->id)->update(['state' => 'declined', 'updated_at' => now()]);
        SecurityPolicy::audit('signature_declined', 'signature_request', (int) $o['request']->id, [], ['signer_id' => (int) $o['signer']->id, 'reason' => $reason]);

        return null;
    }

    /** Everyone signed: file the signature certificate next to the document. */
    private function complete(int $requestId): void
    {
        $r = DB::table('signature_requests')->where('id', $requestId)->first();
        $doc = Document::withoutGlobalScopes()->find($r->document_id);
        $signers = DB::table('signature_signers')->where('signature_request_id', $r->id)->orderBy('position')->get();
        $sender = DB::table('users')->where('id', $r->created_by)->value('name');
        $pdf = Pdf::loadView('signatures.certificate', ['r' => $r, 'doc' => $doc, 'signers' => $signers, 'sender' => $sender,
            'firm' => config('app.name'), 'completed' => now()])->output();

        $filename = 'Signature certificate - '.Str::limit(Str::slug($r->title, ' '), 80, '').'.pdf';
        $cert = app(StoreDocument::class)->handle((int) $r->case_id, $pdf, ['title' => 'Signature certificate: '.$r->title, 'filename' => $filename,
            'mimetype' => 'application/pdf'], null);
        DB::table('signature_requests')->where('id', $r->id)->update(['state' => 'completed', 'completed_at' => now(), 'certificate_document_id' => $cert->id, 'updated_at' => now()]);
        SecurityPolicy::audit('signature_completed', 'signature_request', (int) $r->id, [], ['certificate_document_id' => (int) $cert->id]);
    }

    public function sha(Document $doc): ?string
    {
        $disk = Storage::disk($doc->disk ?: 'local');
        if (! $disk->exists($doc->full_path)) {
            return null;
        }
        $ctx = hash_init('sha256');
        $stream = $disk->readStream($doc->full_path);
        hash_update_stream($ctx, $stream);
        is_resource($stream) && fclose($stream);

        return hash_final($ctx);
    }
}
