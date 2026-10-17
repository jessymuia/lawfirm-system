<?php

namespace App\Http\Controllers;

use App\Email\EmailFiling;
use App\Email\MimeMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FUN-15: the inbound mail webhook. The secret in the URL is the credential (and, when configured, Mailgun's signature).
 * Accepts the raw message as Mailgun's "body-mime", SendGrid's raw "email", or a message/rfc822 body (any MTA with curl).
 * Always answers 200 for a message it has read, filed or not, so providers do not retry what will never be accepted.
 */
class InboundEmailController extends Controller
{
    public function __construct(private EmailFiling $filing) {}

    public function receive(Request $r, string $secret): JsonResponse
    {
        $expected = (string) config('email_ingest.webhook_secret');
        if (strlen($expected) < 32 || ! hash_equals($expected, $secret) || ! $this->mailgunSignatureOk($r)) {
            abort(404);
        }
        $raw = (string) ($r->input('body-mime') ?? $r->input('email') ?? (str_starts_with((string) $r->header('Content-Type'), 'message/rfc822') ? $r->getContent() : ''));
        if ($raw === '' && $r->hasFile('message')) {
            $raw = (string) file_get_contents($r->file('message')->getRealPath());
        }
        if ($raw === '' || strlen($raw) > (int) config('email_ingest.max_message_kb') * 1024) {
            return response()->json(['filed' => 0, 'reason' => 'no message, or too large'], 200);
        }

        $msg = MimeMessage::parse($raw);
        $from = $msg->addresses('from')[0]['email'] ?? null;
        $recipients = array_merge(
            array_column($msg->addresses('to'), 'email'), array_column($msg->addresses('cc'), 'email'),
            array_column($msg->addresses('delivered-to'), 'email'), array_column($msg->addresses('x-original-to'), 'email'),
            preg_split('/[\s,]+/', (string) $r->input('recipient', '')) ?: [], json_decode((string) $r->input('envelope', '{}'), true)['to'] ?? [],
        );
        $cases = $this->filing->casesFor($recipients);
        if (! $cases) {
            return response()->json(['filed' => 0, 'reason' => 'no matter address among the recipients'], 200);
        }
        if (! $this->filing->senderAllowed($from)) {
            Log::notice('Inbound email refused: sender not allowed by the firm policy.', ['from' => $from, 'cases' => $cases]);

            return response()->json(['filed' => 0, 'reason' => 'sender not allowed'], 200);
        }
        $filed = 0;
        foreach ($cases as $caseId) {
            $this->filing->file($caseId, $raw, 'inbound')['duplicate'] || $filed++;
        }

        return response()->json(['filed' => $filed], 200);
    }

    /** Mailgun signs every webhook: HMAC-SHA256(timestamp . token) with the signing key. Checked when a key is set. */
    private function mailgunSignatureOk(Request $r): bool
    {
        $key = (string) config('email_ingest.mailgun_signing_key');
        if ($key === '') {
            return true;
        }
        $ts = (string) $r->input('timestamp');

        return abs(time() - (int) $ts) < 900
            && hash_equals(hash_hmac('sha256', $ts.(string) $r->input('token'), $key), (string) $r->input('signature'));
    }
}
