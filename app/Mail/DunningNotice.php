<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** PLAT-4: a payment reminder for an overdue subscription invoice. */
class DunningNotice extends Mailable
{
    use Queueable;

    /** PLAT-4: $payUrl = card payment link (Stripe configured); $paybill = M-Pesa paybill for KES invoices */
    public function __construct(public string $number, public string $outstanding, public string $dueAt, public int $daysOverdue, public bool $willSuspend,
        public ?string $payUrl = null, public ?string $paybill = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment overdue: invoice {$this->number}");
    }

    public function content(): Content
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $warn = $this->willSuspend ? '<p>If this stays unpaid your account may be suspended. Your data is kept and access returns as soon as payment is received.</p>' : '';

        $ways = '';
        if ($this->payUrl) {
            $ways .= "<p><a href=\"{$e($this->payUrl)}\">Pay by card</a></p>";
        }
        if ($this->paybill) {
            $ways .= "<p>M-Pesa: Lipa na M-Pesa, paybill <b>{$e($this->paybill)}</b>, account number <b>{$e($this->number)}</b>.</p>";
        }

        return new Content(htmlString: "<p>Invoice <b>{$e($this->number)}</b> was due on {$e($this->dueAt)} and {$e($this->outstanding)} is still outstanding ({$this->daysOverdue} days overdue).</p>{$ways}{$warn}<p>If you have already paid, please ignore this message.</p>");
    }
}
