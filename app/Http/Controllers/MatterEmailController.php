<?php

namespace App\Http\Controllers;

use App\Email\EmailFiling;
use App\Http\Requests\MatterEmail\SettingsRequest;
use App\Http\Requests\MatterEmail\UploadRequest;
use App\Models\Cases;
use App\Security\SecurityPolicy;
use App\Support\FirmSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** FUN-15: a matter's filed email: its address, the list, one message, the original, filing an .eml by hand. */
class MatterEmailController extends Controller
{
    public function __construct(private EmailFiling $filing) {}

    public function index(Request $r, int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);   // matter access applies
        $q = trim((string) $r->query('q', ''));
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $rows = DB::table('matter_emails')->where('case_id', $m->id)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('from_email', 'like', $like)
                ->orWhere('from_name', 'like', $like)->orWhere('body_text', 'like', $like)))
            ->orderByDesc(DB::raw('coalesce(sent_at, created_at)'))
            ->paginate(min(100, max(1, (int) $r->query('per_page', 25))), ['id', 'thread_key', 'from_email', 'from_name', 'subject', 'sent_at', 'source', 'created_at',
                DB::raw('(select count(*) from matter_email_attachments a where a.matter_email_id = matter_emails.id) as attachments')]);

        return $this->response(true, 'success', ['address' => $this->filing->addressFor($m), 'emails' => $rows], 200);
    }

    public function show(int $case, int $email): JsonResponse
    {
        $m = Cases::findOrFail($case);
        $e = DB::table('matter_emails')->where('case_id', $m->id)->where('id', $email)->first();
        abort_if(! $e, 404);

        return $this->response(true, 'success', [
            'id' => (int) $e->id, 'subject' => $e->subject, 'from_email' => $e->from_email, 'from_name' => $e->from_name,
            'to' => json_decode((string) $e->to_list, true), 'cc' => json_decode((string) $e->cc_list, true), 'sent_at' => $e->sent_at,
            'body_text' => $e->body_text, 'had_html' => (bool) $e->had_html, 'source' => $e->source,
            'skipped_attachments' => json_decode((string) $e->skipped_attachments, true) ?: [],
            'attachments' => DB::table('matter_email_attachments')->join('documents', 'documents.id', '=', 'matter_email_attachments.document_id')
                ->where('matter_email_id', $e->id)->whereNull('documents.deleted_at')->get(['documents.id', 'documents.filename', 'documents.filesize']),
            'thread' => DB::table('matter_emails')->where('case_id', $m->id)->where('thread_key', $e->thread_key)->where('id', '!=', $e->id)
                ->orderBy('sent_at')->get(['id', 'subject', 'from_email', 'sent_at']),
        ], 200);
    }

    public function upload(UploadRequest $r, int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);
        $raw = (string) file_get_contents($r->file('file')->getRealPath());
        if (! preg_match('/^(from|received|return-path|message-id|date|subject|mime-version|to|delivered-to|x-[a-z-]+)\s*:/im', substr($raw, 0, 4096))) {
            return $this->response(false, 'That is not an email file. Save the message as .eml (Outlook .msg files are not supported).', null, 422);
        }
        $out = $this->filing->file((int) $m->id, $raw, 'upload', (int) Auth::id());

        return $this->response(true, $out['duplicate'] ? 'This email was already filed to the matter.' : 'Email filed.', $out, $out['duplicate'] ? 200 : 201);
    }

    public function raw(int $case, int $email)
    {
        $m = Cases::findOrFail($case);
        $e = DB::table('matter_emails')->where('case_id', $m->id)->where('id', $email)->first();
        abort_if(! $e, 404);
        SecurityPolicy::audit('email_original_downloaded', 'matter_email', (int) $e->id, [], []);

        return Storage::disk($e->raw_disk)->download($e->raw_path, 'email-'.$e->id.'.eml', ['Content-Type' => 'message/rfc822']);
    }

    /** Removes the filed message (its attachments stay in the matter's documents). */
    public function destroy(int $case, int $email): JsonResponse
    {
        $m = Cases::findOrFail($case);
        $e = DB::table('matter_emails')->where('case_id', $m->id)->where('id', $email)->first();
        abort_if(! $e, 404);
        DB::table('matter_emails')->where('id', $e->id)->delete();
        Storage::disk($e->raw_disk)->delete($e->raw_path);
        SecurityPolicy::audit('email_unfiled', 'matter_email', (int) $e->id, ['subject' => $e->subject, 'case_id' => $e->case_id], []);

        return $this->response(true, 'Email removed from the matter.', null, 200);
    }

    public function settings(): JsonResponse
    {
        return $this->response(true, 'success', ['senders' => FirmSettings::get('email_ingest_senders', 'staff'),
            'enabled' => (bool) config('email_ingest.domain') && strlen((string) config('email_ingest.webhook_secret')) >= 32], 200);
    }

    public function saveSettings(SettingsRequest $r): JsonResponse
    {
        $old = FirmSettings::get('email_ingest_senders', 'staff');
        FirmSettings::set('email_ingest_senders', $r->validated('senders'), (int) Auth::id());
        SecurityPolicy::audit('email_filing_policy_changed', 'firm_setting', 0, ['senders' => $old], ['senders' => $r->validated('senders')]);

        return $this->response(true, 'Saved.', ['senders' => $r->validated('senders')], 200);
    }
}
