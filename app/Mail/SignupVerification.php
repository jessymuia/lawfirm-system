<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** PLAT-13: proves the person owns the address before a firm is created for it. */
class SignupVerification extends Mailable
{
    use Queueable;

    public function __construct(public string $firmName, public string $link, public int $hours) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email to create '.$this->firmName);
    }

    public function content(): Content
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);

        return new Content(htmlString: "<p>Someone (we hope you) asked to create <b>{$e($this->firmName)}</b> with this email address.</p>"
            ."<p><a href=\"{$e($this->link)}\">Confirm your email and create the firm</a></p>"
            ."<p>The link works for {$this->hours} hours. If this was not you, ignore this message: nothing is created until the link is opened.</p>");
    }
}
