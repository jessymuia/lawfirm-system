<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** FUN-14: asks a signer to read and sign a document (their personal, secret link). */
class SignatureRequested extends Mailable
{
    use Queueable;

    public function __construct(public string $firm, public string $signer, public string $title, public ?string $note, public string $link, public string $expires) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->firm}: please sign \"{$this->title}\"");
    }

    public function content(): Content
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);

        return new Content(htmlString: "<p>Dear {$e($this->signer)},</p><p><b>{$e($this->firm)}</b> asks you to read and sign <b>{$e($this->title)}</b>.</p>"
            .($this->note ? '<p>'.nl2br($e($this->note)).'</p>' : '')
            ."<p><a href=\"{$e($this->link)}\">Read and sign the document</a></p>"
            ."<p>This link is for you alone and works until {$e($this->expires)}. If you were not expecting it, contact the firm before signing.</p>");
    }
}
