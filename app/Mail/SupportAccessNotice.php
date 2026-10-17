<?php

namespace App\Mail;

use App\Models\SupportAccessSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** PLAT-8: tells the firm's administrators that platform support asked for, or started, access. */
class SupportAccessNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportAccessSession $session, public string $headline) {}

    public function build()
    {
        return $this->subject('Platform support access: '.$this->session->status)->view('emails.support_access_notice');
    }
}
