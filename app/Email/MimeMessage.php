<?php

namespace App\Email;

use Carbon\CarbonImmutable;

/**
 * FUN-15: a parsed email (RFC 5322 + MIME): headers, the readable text, and the attachments. Enough of the standards for
 * filing mail: folded headers, encoded words (RFC 2047), nested multiparts, base64 / quoted-printable, charsets, and
 * attachment names (including RFC 2231). HTML is only ever turned into text, never kept for display.
 */
class MimeMessage
{
    /** @var array<string, list<string>> lower-case header name => decoded values */
    public array $headers = [];

    public string $text = '';

    public bool $hadHtml = false;

    /** @var list<array{filename: string, mime: string, content: string}> */
    public array $attachments = [];

    private string $html = '';

    public static function parse(string $raw): self
    {
        $m = new self;
        $raw = str_replace("\r\n", "\n", $raw);
        [$head, $body] = self::split($raw);
        $m->headers = self::headers($head);
        $m->walk($m->headers, $body, 0);
        if (trim($m->text) === '' && $m->html !== '') {
            $m->text = self::htmlToText($m->html);
        }
        $m->text = trim(str_replace("\r", '', $m->text));

        return $m;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function subject(): string
    {
        return trim((string) $this->header('subject'));
    }

    public function messageId(): ?string
    {
        $id = trim((string) $this->header('message-id'), " <>\t");

        return $id !== '' ? mb_substr($id, 0, 250) : null;
    }

    /** The conversation this belongs to: the first Message-ID in References, else In-Reply-To, else itself. */
    public function threadKey(): string
    {
        preg_match_all('/<([^>]+)>/', (string) $this->header('references').' '.(string) $this->header('in-reply-to'), $m);

        return hash('sha256', $m[1][0] ?? ($this->messageId() ?? $this->subject()));
    }

    public function date(): ?CarbonImmutable
    {
        try {
            return $this->header('date') ? CarbonImmutable::parse((string) $this->header('date')) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{email: string, name: ?string}> */
    public function addresses(string $header): array
    {
        $out = [];
        foreach ($this->headers[strtolower($header)] ?? [] as $value) {
            // split on commas that are not inside quotes or angle brackets
            foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<]*>)/', $value) ?: [] as $part) {
                if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $part, $mm)) {
                    $out[] = ['email' => strtolower(trim($mm[2])), 'name' => trim($mm[1]) !== '' ? trim($mm[1]) : null];
                } elseif (filter_var(trim($part), FILTER_VALIDATE_EMAIL)) {
                    $out[] = ['email' => strtolower(trim($part)), 'name' => null];
                }
            }
        }

        return $out;
    }

    // ---- parsing ----

    private function walk(array $headers, string $body, int $depth): void
    {
        if ($depth > 10) {
            return;   // a pathological nesting is not worth following
        }
        [$type, $params] = self::contentType($headers['content-type'][0] ?? 'text/plain');
        $disposition = strtolower(trim(explode(';', $headers['content-disposition'][0] ?? '')[0]));
        $filename = self::filename($headers);

        if (str_starts_with($type, 'multipart/') && isset($params['boundary'])) {
            foreach (self::parts($body, $params['boundary']) as $part) {
                [$h, $b] = self::split($part);
                $this->walk(self::headers($h), $b, $depth + 1);
            }

            return;
        }
        $content = self::decode($body, strtolower(trim($headers['content-transfer-encoding'][0] ?? '7bit')));

        if ($type === 'message/rfc822' && $disposition !== 'attachment') {
            // a forwarded message: its text joins this one, its attachments too
            $inner = self::parse($content);
            $this->text .= "\n\n---------- Forwarded message ----------\nFrom: ".($inner->header('from') ?? '')."\nSubject: ".$inner->subject()."\n\n".$inner->text;
            array_push($this->attachments, ...$inner->attachments);

            return;
        }
        if ($disposition !== 'attachment' && $filename === null && in_array($type, ['text/plain', 'text/html'], true)) {
            $content = self::toUtf8($content, $params['charset'] ?? 'utf-8');
            if ($type === 'text/plain') {
                $this->text .= ($this->text !== '' ? "\n\n" : '').$content;
            } else {
                $this->hadHtml = true;
                $this->html .= $content;
            }

            return;
        }
        $this->attachments[] = ['filename' => $filename ?? ('attachment-'.(count($this->attachments) + 1).self::extensionFor($type)), 'mime' => $type, 'content' => $content];
    }

    /** @return array{0: string, 1: string} header block, body */
    private static function split(string $raw): array
    {
        $at = strpos($raw, "\n\n");

        return $at === false ? [$raw, ''] : [substr($raw, 0, $at), substr($raw, $at + 2)];
    }

    /** @return array<string, list<string>> */
    private static function headers(string $block): array
    {
        $out = [];
        $block = (string) preg_replace("/\n[ \t]+/", ' ', $block);   // unfold
        foreach (explode("\n", $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $out[strtolower(trim($name))][] = self::words(trim($value));
        }

        return $out;
    }

    /** RFC 2047 encoded words ("=?utf-8?B?...?=") to UTF-8. */
    private static function words(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $value;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function contentType(string $value): array
    {
        $parts = explode(';', $value);
        $params = [];
        foreach (array_slice($parts, 1) as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $params[strtolower(trim($k))] = trim(trim($v), '"');
            }
        }

        return [strtolower(trim($parts[0])), $params];
    }

    private static function filename(array $headers): ?string
    {
        foreach (['content-disposition', 'content-type'] as $h) {
            $value = $headers[$h][0] ?? '';
            // RFC 2231: filename*=utf-8''name%20with%20spaces (possibly split as filename*0*=…)
            if (preg_match_all("/(?:file)?name\\*(?:\\d+)?\\*?=\\s*(?:([^']*)'[^']*')?\"?([^\";]+)\"?/i", $value, $m)) {   // one or more matches
                $name = rawurldecode(implode('', $m[2]));

                return self::cleanName(self::toUtf8($name, $m[1][0] ?: 'utf-8'));
            }
            if (preg_match('/(?:file)?name\s*=\s*"?([^";]+)"?/i', $value, $m)) {
                return self::cleanName($m[1]);
            }
        }

        return null;
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/', '_', basename(str_replace('\\', '/', $name))));

        return $name !== '' ? mb_substr($name, 0, 180) : 'attachment';
    }

    /** @return list<string> */
    private static function parts(string $body, string $boundary): array
    {
        $chunks = explode("\n--".$boundary, "\n".$body);
        array_shift($chunks);   // the preamble
        $out = [];
        foreach ($chunks as $c) {
            if (str_starts_with($c, '--')) {
                break;   // the closing boundary
            }
            $out[] = ltrim(substr($c, (int) strpos($c, "\n")), "\n");
        }

        return $out;
    }

    private static function decode(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private static function toUtf8(string $s, string $charset): string
    {
        $charset = strtolower(trim($charset));
        if (in_array($charset, ['utf-8', 'utf8', 'us-ascii', ''], true)) {
            return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $converted = @iconv($charset, 'UTF-8//IGNORE', $s);

        return $converted !== false ? $converted : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6]|blockquote)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace("/[ \t]+/", ' ', $text)));
    }

    private static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => '.pdf', 'image/png' => '.png', 'image/jpeg' => '.jpg', 'text/plain' => '.txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx', default => '',
        };
    }
}
